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
use craft\helpers\DateTimeHelper;
use DateInterval;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\UnshortenedLinkRecipientsService;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Successful delivery establishes one shared validity window; waiting,
 * failed attempts and later channels cannot consume or move that window.
 *
 * @since 5.16.0
 */
final class InvitationDeliveryValidityTest extends InvitationDeliveryTestCase
{
    private int $clockPauses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-03-01 09:00:00');
    }

    protected function cleanupExternalState(): void
    {
        while ($this->clockPauses > 0) {
            DateTimeHelper::resume();
            $this->clockPauses--;
        }
        parent::cleanupExternalState();
    }

    /** @return array<string, array{string, string|null, string}> */
    public static function firstSends(): array
    {
        return [
            'immediate SMS' => ['sms', null, '2026-03-01 09:00:01'],
            'immediate email' => ['email', null, '2026-03-01 09:00:01'],
            'ten day delay' => ['sms', 'P10D', '2026-03-11 09:00:00'],
            'late worker' => ['email', 'P10D', '2026-04-01 09:00:00'],
            'expired provisional deadline' => ['sms', null, '2026-04-01 09:00:00'],
        ];
    }

    #[DataProvider('firstSends')]
    public function testFirstSuccessfulSendProvidesTheFullPeriod(string $channel, ?string $delay, string $sentAt): void
    {
        $campaign = $this->campaign('P14D', $delay);
        $recipient = $this->seedPendingRecipient($campaign);
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
        $this->at($sentAt);

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        self::assertSame((new DateTime($sentAt))->add(new DateInterval('P14D'))->format('Y-m-d H:i:s'), $this->expiry($recipient));
        self::assertSame($sentAt, $this->sendDate($recipient, $channel)?->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->sentTo($channel));
    }

    /** @return array<string, array{string, string}> */
    public static function channelOrders(): array
    {
        return ['SMS first' => ['sms', 'email'], 'email first' => ['email', 'sms']];
    }

    #[DataProvider('channelOrders')]
    public function testLaterChannelAndDuplicateJobsNeverMoveTheWindow(string $first, string $second): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->at('2026-03-02 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        $expiry = $this->expiry($recipient);
        $this->setPeriod($campaign, 'P30D');
        $this->at('2026-03-03 09:00:00');
        $batch = $this->batch($campaign, [$recipient]);
        $this->runBatch($batch);
        $this->at('2026-03-04 09:00:00');
        $this->runBatch($batch);

        self::assertSame('2026-03-16 09:00:00', $expiry);
        self::assertSame($expiry, $this->expiry($recipient));
        self::assertCount(1, $this->sentTo($first));
        self::assertCount(1, $this->sentTo($second));
        self::assertSame('2026-03-02 09:00:00', $this->sendDate($recipient, $first)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-03 09:00:00', $this->sendDate($recipient, $second)?->format('Y-m-d H:i:s'));
    }

    #[DataProvider('channelOrders')]
    public function testNoExpiryFirstSendStaysUnlimitedAfterSettingsChange(string $first, string $second): void
    {
        $campaign = $this->campaign(null);
        $recipient = $this->seedPendingRecipient($campaign);
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        self::assertNotNull($this->sendDate($recipient, $first));
        self::assertNull($this->expiry($recipient));
        $this->setPeriod($campaign, 'P14D');
        $this->at('2026-03-20 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $second));
        self::assertNotNull($this->sendDate($recipient, $second));
        self::assertNull($this->expiry($recipient));
    }

    #[DataProvider('channelOrders')]
    public function testRemovingExpiryAfterFirstSendCannotClearTheStoredDeadline(string $first, string $second): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->at('2026-03-02 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        $this->setPeriod($campaign, null);
        $this->at('2026-03-03 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $second));
        self::assertSame('2026-03-16 09:00:00', $this->expiry($recipient));
        self::assertNotNull($this->sendDate($recipient, $second));
    }

    #[DataProvider('channelOrders')]
    public function testRemovingExpiryBeforeFirstSendReplacesTheProvisionalDeadline(string $first, string $second): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        self::assertNotNull($this->expiry($recipient));
        $this->setPeriod($campaign, null);
        $this->at('2026-03-20 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        self::assertNull($this->expiry($recipient));
        self::assertNotNull($this->sendDate($recipient, $first));
        $this->setPeriod($campaign, 'P14D');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $second));
        self::assertNull($this->expiry($recipient));
    }

    public function testTheSettingAtSuccessIsUsedEvenWhenItChangesDuringTransport(): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->setPeriod($campaign, 'P7D');
        $this->at('2026-03-02 09:00:00');
        $this->duringTheFirstSend(self::SMS, function() use ($campaign): void {
            $this->setPeriod($campaign, 'P3D');
            $this->at('2026-03-02 10:00:00');
        });
        $this->runBatch($this->channelBatch($campaign, [$recipient], self::SMS));
        self::assertSame('2026-03-05 10:00:00', $this->expiry($recipient));
    }

    #[DataProvider('channelOrders')]
    public function testKnownFailureLeavesProvisionalExpiryAndSuccessStartsTheWindow(string $first, string $second): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->at('2026-03-20 09:00:00');
        $this->answerSendsWith($first, [false]);
        try {
            $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
            self::fail('The failed delivery must surface.');
        } catch (SendBatchFailedException) {
        }
        self::assertNull($this->sendDate($recipient, $first));
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
        $this->at('2026-03-21 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $second));
        self::assertSame('2026-04-04 09:00:00', $this->expiry($recipient));
        $this->at('2026-03-22 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        self::assertSame('2026-04-04 09:00:00', $this->expiry($recipient));
    }

    #[DataProvider('channelOrders')]
    public function testRetryStartsValidityOnlyAfterTheSuccessfulAttempt(string $channel, string $unused): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->answerSendsWith($channel, [false, true]);
        $batch = $this->channelBatch($campaign, [$recipient], $channel);
        $this->at('2026-03-20 09:00:00');
        try {
            $this->runBatch($batch);
            self::fail('The failed delivery must surface.');
        } catch (SendBatchFailedException) {
        }
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
        $this->at('2026-03-21 09:00:00');
        $this->runBatch($batch);
        self::assertSame('2026-04-04 09:00:00', $this->expiry($recipient));
        self::assertCount(2, $this->sentTo($channel));
    }

    #[DataProvider('channelOrders')]
    public function testPendingChannelIsSkippedAtTheDeliveredDeadline(string $first, string $second): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->runBatch($this->channelBatch($campaign, [$recipient], $first));
        $this->at('2026-03-15 09:00:00');
        $this->runBatch($this->channelBatch($campaign, [$recipient], $second));
        self::assertSame([], $this->sentTo($second));
        self::assertNull($this->sendDate($recipient, $second));
        self::assertSame($this->counts($second, 0), $this->batchCounts($campaign)[1]);
    }

    public function testManuallyAnsweredInvitationIsNotSent(): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $form = $campaign->getForm();
        self::assertNotNull($form);
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $this->siteId);
        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
        $this->runBatch($this->batch($campaign, [$recipient]));
        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame([], $this->sentTo(self::EMAIL));
        self::assertSame([['smsSent' => 0, 'emailSent' => 0, 'errors' => 0]], $this->batchCounts($campaign));
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
    }

    public function testDisabledCampaignDoesNotStartAWindow(): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->disableOwnedCampaignForSite($campaign, $this->siteId);
        $this->at('2026-03-20 09:00:00');
        $this->runBatch($this->batch($campaign, [$recipient], ['onlyWhileCampaignEnabled' => true]));
        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame([], $this->sentTo(self::EMAIL));
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
    }

    #[DataProvider('channelOrders')]
    public function testUnreadableDurationReachesNeitherShortenerNorTransport(string $channel, string $unused): void
    {
        $campaign = $this->campaign('P14D');
        $recipient = $this->seedPendingRecipient($campaign);
        // The duration UI previously accepted this unreadable numeric shape.
        Craft::$app->getDb()->createCommand()->update(CampaignRecord::tableName(), [
            'invitationExpiryPeriod' => 'PT213213213213123H',
        ], ['id' => $campaign->id])->execute();
        $failure = null;
        try {
            $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertNotNull($failure);
        self::assertSame([], $this->sentTo($channel));
        $links = CampaignManager::$plugin->recipients;
        self::assertInstanceOf(UnshortenedLinkRecipientsService::class, $links);
        self::assertSame(0, $links->shortenerCalls);
        self::assertNull($this->sendDate($recipient, $channel));
        self::assertSame('2026-03-15 09:00:00', $this->expiry($recipient));
    }

    private function campaign(?string $period, ?string $delay = null): Campaign
    {
        $campaign = $this->seedDeliverableCampaign();
        $campaign->invitationExpiryPeriod = $period;
        $campaign->invitationDelayPeriod = $delay;
        $this->saveTestElement($campaign, false, true, false);
        return $campaign;
    }

    private function setPeriod(Campaign $campaign, ?string $period): void
    {
        $campaign->invitationExpiryPeriod = $period;
        $this->saveTestElement($campaign, false, true, false);
    }

    private function expiry(RecipientRecord $recipient): ?string
    {
        return $this->reloadRecipient($recipient)->invitationExpiryDate?->format('Y-m-d H:i:s');
    }

    private function at(string $time): void
    {
        DateTimeHelper::pause(new DateTime($time));
        $this->clockPauses++;
    }
}
