<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use lindemannrock\campaignmanager\tests\Support\ProviderTransportTestCase;
use lindemannrock\campaignmanager\tests\Support\TransportIsolation;

/**
 * Intercepted MPP HTTP crosses the real producer, wrappers and persistence.
 *
 * @since 5.16.0
 */
final class CampaignMppTransportTest extends ProviderTransportTestCase
{
    public function testIpBlockAfterSuccessCarriesTheProviderDelayAndOnlyRemainingIds(): void
    {
        $campaign = $this->mppCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->http('OK,smsid:synthetic-id,mobiles:1', 'error,your ip is blocked until next 10 minute(s): 192.0.2.1');
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame(2, TransportIsolation::$interceptedRequests - $before);
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame(660, $queued[0]['delay']);
        self::assertSame([(int)$recipients[1]->id, (int)$recipients[2]->id], $queued[0]['job']->recipientIds);
        self::assertFalse($queued[0]['job']->sendEmail);
        self::assertSame([['smsSent' => 1, 'emailSent' => 3, 'errors' => 0]], $this->batchCounts($campaign));
        self::assertNotNull($this->sendDate($recipients[0], self::SMS));
        self::assertNull($this->sendDate($recipients[1], self::SMS));
        self::assertNull($this->sendDate($recipients[2], self::SMS));
    }

    public function testInactiveAccountMakesOneHttpAttemptAndEmailContinues(): void
    {
        $campaign = $this->mppCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->http('error,user is inactive');
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame(1, TransportIsolation::$interceptedRequests - $before);
        self::assertSame([['smsSent' => 0, 'emailSent' => 2, 'errors' => 0]], $this->batchCounts($campaign));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
        foreach ($recipients as $recipient) {
            self::assertNull($this->sendDate($recipient, self::SMS));
            self::assertNotNull($this->sendDate($recipient, self::EMAIL));
        }
    }

    public function testExistingTimedCooldownSuppressesHttpAndQueuesExactlyThePendingRecipients(): void
    {
        $campaign = $this->mppCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->hold('ip-blocked', 120);
        $dispatch = new \lindemannrock\campaignmanager\tests\Stubs\ScriptedSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $dispatch);
        $before = TransportIsolation::$interceptedRequests;
        $batch = $this->batch($campaign, $recipients);
        $this->runBatch($batch);
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        self::assertSame([], $dispatch->sentMessages, 'An existing hold suppresses even SMS Manager dispatch.');
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame(120, $queued[0]['delay']);
        self::assertSame($batch->recipientIds, $queued[0]['job']->recipientIds);
    }

    public function testUnavailableGuardNeverAttemptsHttp(): void
    {
        $campaign = $this->mppCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $before = TransportIsolation::$interceptedRequests;
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $this->runBatch($this->channelBatch($campaign, [$recipient], self::SMS));
            self::assertSame([], $this->queuedJobs((int)$campaign->id));
            self::assertSame([['smsSent' => 0, 'emailSent' => 0, 'errors' => 0]], $this->batchCounts($campaign));
        } finally {
            $transaction->rollBack();
        }
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        self::assertNull($this->sendDate($recipient, self::SMS));
    }
}
