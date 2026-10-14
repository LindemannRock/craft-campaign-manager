<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Connection;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\InMemorySession;
use lindemannrock\campaignmanager\tests\Stubs\InterceptedRecipientWrite;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The database, rather than a stale worker record, chooses which successful
 * channel establishes expiry. Failed persistence cannot masquerade as success.
 *
 * @since 5.16.0
 */
final class InvitationDeliveryPersistenceTest extends InvitationDeliveryTestCase
{
    private int $clockPauses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-03-01 09:00:00');
    }

    protected function cleanupExternalState(): void
    {
        InterceptedRecipientWrite::$beforeWrite = null;
        while ($this->clockPauses > 0) {
            DateTimeHelper::resume();
            $this->clockPauses--;
        }
        parent::cleanupExternalState();
    }

    /** @return array<string, array{string, string}> */
    public static function channels(): array
    {
        return ['SMS paused' => ['sms', 'email'], 'email paused' => ['email', 'sms']];
    }

    #[DataProvider('channels')]
    public function testOverlappingChannelWritesEstablishExactlyOneWindow(string $paused, string $winner): void
    {
        $campaign = $this->campaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $application = Craft::$app;
        $proxy = new SessionApplication($application, new InMemorySession());
        /** @var Connection $otherDb */
        $otherDb = Craft::createObject(App::dbConfig());
        $db = $application->getDb();
        $originalCommand = $db->commandClass;
        $this->at('2026-03-02 09:00:00');
        $db->commandClass = InterceptedRecipientWrite::class;
        InterceptedRecipientWrite::$beforeWrite = function() use ($campaign, $recipient, $winner, $otherDb, $proxy): ?int {
            self::assertSame([], $this->sentTo($winner));
            $this->at('2026-03-03 09:00:00');
            $previous = Craft::$app;
            Craft::$app = $proxy;
            try {
                $proxy->withLocalComponents(['db' => $otherDb], fn() => $this->runBatch($this->channelBatch($campaign, [$recipient], $winner)));
            } finally {
                Craft::$app = $previous;
            }
            $this->at('2026-03-04 09:00:00');
            return null;
        };
        try {
            $this->runBatch($this->channelBatch($campaign, [$recipient], $paused));
            self::assertNull(InterceptedRecipientWrite::$beforeWrite);
        } finally {
            $db->commandClass = $originalCommand;
            InterceptedRecipientWrite::$beforeWrite = null;
            $otherDb->close();
        }
        $stored = $this->reloadRecipient($recipient);
        self::assertSame('2026-03-17 09:00:00', $stored->invitationExpiryDate?->format('Y-m-d H:i:s'));
        self::assertNotNull($stored->smsSendDate);
        self::assertNotNull($stored->emailSendDate);
        self::assertCount(1, $this->sentTo($paused));
        self::assertCount(1, $this->sentTo($winner));
        self::assertSame('2026-03-02 09:00:00', $this->sendDate($recipient, $paused)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-03 09:00:00', $this->sendDate($recipient, $winner)?->format('Y-m-d H:i:s'));
        $this->assertDeliveryLockIsFree($recipient, $paused);
        $this->assertDeliveryLockIsFree($recipient, $winner);
    }

    #[DataProvider('channels')]
    public function testAcceptedTransportWithFailedPersistenceIsReportedWithoutAnotherSend(string $channel, string $unused): void
    {
        $campaign = $this->campaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->at('2026-03-02 09:00:00');
        $db = Craft::$app->getDb();
        $originalCommand = $db->commandClass;
        $db->commandClass = InterceptedRecipientWrite::class;
        InterceptedRecipientWrite::$beforeWrite = static fn(): int => 0;
        $failure = null;
        try {
            $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));
        } catch (Throwable $e) {
            $failure = $e;
        } finally {
            $db->commandClass = $originalCommand;
            InterceptedRecipientWrite::$beforeWrite = null;
        }
        self::assertNotNull($failure, 'Accepted transport with no durable write must surface as a failure.');
        self::assertCount(1, $this->sentTo($channel), 'Persistence failure cannot repeat transport inside this attempt.');
        self::assertNull($this->sendDate($recipient, $channel));
        self::assertSame('2026-03-15 09:00:00', $this->reloadRecipient($recipient)->invitationExpiryDate?->format('Y-m-d H:i:s'));
        $this->assertDeliveryLockIsFree($recipient, $channel);
    }

    /** @return array<string, array{string|null}> */
    public static function historicalExpiries(): array
    {
        return ['legacy null' => [null], 'creation deadline' => ['2026-03-15 09:00:00']];
    }

    #[DataProvider('historicalExpiries')]
    public function testSentHistoricalRecipientsKeepTheirStoredExpiry(?string $expiry): void
    {
        $campaign = $this->campaign();
        // These are the exact product-authored shapes of historical recipients.
        $recipient = $this->seedPendingRecipient($campaign);
        Craft::$app->getDb()->createCommand()->update(RecipientRecord::tableName(), [
            'smsSendDate' => Db::prepareDateForDb(new DateTime('2026-03-01 09:00:00')),
            'invitationExpiryDate' => $expiry === null ? null : Db::prepareDateForDb(new DateTime($expiry)),
        ], ['id' => $recipient->id])->execute();
        $before = $this->reloadRecipient($recipient)->getAttributes();
        $this->runBatch($this->channelBatch($campaign, [$recipient], self::SMS));
        self::assertEquals($before, $this->reloadRecipient($recipient)->getAttributes(), 'A read/skip must never normalize existing rows.');
        $this->at('2026-03-02 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], self::EMAIL));
        self::assertSame($expiry, $this->reloadRecipient($recipient)->invitationExpiryDate?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 09:00:00', $this->sendDate($recipient, self::SMS)?->format('Y-m-d H:i:s'));
    }

    public function testAnalyticsCountsStoredCorrectedDeadlines(): void
    {
        $campaign = $this->campaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->at('2026-03-20 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], self::SMS));
        $analytics = CampaignManager::$plugin->analytics;
        $stats = $analytics->getOverviewStats((int)$campaign->id, [$this->siteId], 'all');
        self::assertSame(0, $stats['expired']);
        self::assertSame(1, $stats['smsSent']);
        self::assertSame(0, $stats['emailsSent']);
        $this->at('2026-04-03 09:00:00');
        self::assertSame(1, $analytics->getOverviewStats((int)$campaign->id, [$this->siteId], 'all')['expired']);
    }

    private function campaign(): Campaign
    {
        $campaign = $this->seedDeliverableCampaign();
        $campaign->invitationExpiryPeriod = 'P14D';
        $this->saveTestElement($campaign, false, true, false);
        return $campaign;
    }

    private function at(string $time): void
    {
        DateTimeHelper::pause(new DateTime($time));
        $this->clockPauses++;
    }
}
