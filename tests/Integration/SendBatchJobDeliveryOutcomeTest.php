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
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\RecordingProgressQueue;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * What a send batch records for each outcome of an invitation: sent, failed,
 * already sent, being sent by another worker, or recipient gone. Only a sent
 * invitation gets a send date and counts as sent; only a failure or a
 * missing recipient counts as an error. A failed or interrupted send leaves
 * the invitation free for a later batch, which sends it once. Progress and
 * the batch's activity log cover every recipient whatever the outcome, and
 * a batch keeps the settings and stored format it always had.
 *
 * @since 5.16.0
 */
#[CoversClass(SendBatchJob::class)]
final class SendBatchJobDeliveryOutcomeTest extends InvitationDeliveryTestCase
{
    private const ADD_PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
    ];

    /**
     * Every setting a queued batch carries.
     */
    private const BATCH_SETTINGS = [
        'description',
        'campaignId',
        'siteId',
        'recipientIds',
        'sendSms',
        'sendEmail',
        'triggeredByUserId',
        'onlyWhileCampaignEnabled',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function channels(): array
    {
        return [
            'SMS' => [self::SMS],
            'email' => [self::EMAIL],
        ];
    }

    public function testAFailedChannelIsCountedWithoutUndoingTheChannelThatWasSent(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->answerSendsWith(self::SMS, [false]);

        // One channel got through, so the batch completes instead of failing
        $this->runBatch($this->batch($campaign, [$recipient]));

        self::assertSame([$recipient->sms], $this->sentTo(self::SMS));
        self::assertNull($this->sendDate($recipient, self::SMS));
        self::assertSame([$recipient->email], $this->sentTo(self::EMAIL));
        self::assertNotNull($this->sendDate($recipient, self::EMAIL));
        self::assertSame([['smsSent' => 0, 'emailSent' => 1, 'errors' => 1]], $this->batchCounts($campaign));
        $this->assertDeliveryLockIsFree($recipient, self::SMS);
        $this->assertDeliveryLockIsFree($recipient, self::EMAIL);
    }

    #[DataProvider('channels')]
    public function testAFailedInvitationIsSentOnceByALaterBatch(string $channel): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->answerSendsWith($channel, [false]);
        $failing = $this->channelBatch($campaign, [$recipient], $channel);

        try {
            $this->runBatch($failing);
            self::fail('A batch that sent nothing and had an error must fail.');
        } catch (SendBatchFailedException $e) {
            // Retrying a batch that could not send anything is left to the operator
            self::assertFalse($failing->canRetry(1, $e));
        }

        self::assertNull($this->sendDate($recipient, $channel));
        $this->assertDeliveryLockIsFree($recipient, $channel);

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));
        self::assertNotNull($this->sendDate($recipient, $channel));

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        // One failed attempt, one delivery, and nothing more after that
        self::assertSame(
            [$this->contact($recipient, $channel), $this->contact($recipient, $channel)],
            $this->sentTo($channel),
        );
        self::assertSame(
            [$this->counts($channel, 0, 1), $this->counts($channel, 1), $this->counts($channel, 0)],
            $this->batchCounts($campaign),
        );
    }

    public function testAProviderErrorLeavesTheInvitationFreeForTheRetry(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->answerSendsWith(self::SMS, [new RuntimeException('The provider is unreachable.')]);
        $batch = $this->channelBatch($campaign, [$recipient], self::SMS);

        try {
            $this->runBatch($batch);
            self::fail('A provider error must surface to the queue.');
        } catch (RuntimeException $e) {
            self::assertSame('The provider is unreachable.', $e->getMessage());
            // The queue retries the batch
            self::assertTrue($batch->canRetry(1, $e));
        }

        self::assertNull($this->sendDate($recipient, self::SMS));
        self::assertSame([], $this->batchCounts($campaign));
        $this->assertDeliveryLockIsFree($recipient, self::SMS);

        $this->runBatch($batch);

        self::assertSame([$recipient->sms, $recipient->sms], $this->sentTo(self::SMS));
        self::assertNotNull($this->sendDate($recipient, self::SMS));
        self::assertSame([$this->counts(self::SMS, 1)], $this->batchCounts($campaign));
        $this->assertDeliveryLockIsFree($recipient, self::SMS);
    }

    public function testARecipientDeletedWhileTheBatchRunsGetsNothingAndCountsAsOneMissingRecipient(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $kept = $this->seedPendingRecipient($campaign);
        $deleted = $this->seedPendingRecipient($campaign);
        // Both recipients exist when the batch loads them; the second one is
        // deleted while the first one's SMS is on its way.
        $this->duringTheFirstSend(
            self::SMS,
            static fn() => CampaignManager::$plugin->recipients->deleteRecipientById((int)$deleted->id),
        );

        $this->runBatch($this->batch($campaign, [$kept, $deleted]));

        self::assertSame([$kept->sms], $this->sentTo(self::SMS));
        self::assertSame([$kept->email], $this->sentTo(self::EMAIL));
        self::assertSame([['smsSent' => 1, 'emailSent' => 1, 'errors' => 1]], $this->batchCounts($campaign));
        $this->assertDeliveryLockIsFree($deleted, self::SMS);
        $this->assertDeliveryLockIsFree($deleted, self::EMAIL);
    }

    public function testProgressAndTheBatchLogCoverEveryRecipientWhateverItsOutcome(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $sent = $this->seedPendingRecipient($campaign);
        $beingSentElsewhere = $this->seedPendingRecipient($campaign);
        $sentMeanwhile = $this->seedPendingRecipient($campaign);
        $worker = $this->anotherWorker();
        self::assertTrue($worker->acquire($this->deliveryLockName($beingSentElsewhere, self::SMS)));
        $other = $this->channelBatch($campaign, [$sentMeanwhile], self::SMS);
        $this->duringTheFirstSend(self::SMS, fn() => $this->runBatch($other));
        $queue = new RecordingProgressQueue();

        $this->channelBatch($campaign, [$sent, $beingSentElsewhere, $sentMeanwhile], self::SMS)->execute($queue);

        self::assertSame([33, 67, 100], $queue->progress);
        self::assertSame([$sent->sms, $sentMeanwhile->sms], $this->sentTo(self::SMS));
        self::assertNull($this->sendDate($beingSentElsewhere, self::SMS));

        $logs = $this->batchLogs($campaign);
        self::assertCount(2, $logs);
        $log = $logs[1];
        self::assertSame(
            [
                'siteId' => $this->siteId,
                'siteName' => Craft::$app->getSites()->getSiteById($this->siteId)?->name,
                'recipientCount' => 3,
                'smsSent' => 1,
                'emailSent' => 0,
                'errors' => 0,
                'triggeredByUserId' => null,
            ],
            $this->logDetails($log),
        );
        self::assertSame('queue', $log->source);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $log->siteScope);
        self::assertSame([$this->siteId], $this->logSiteIds((int)$log->id));
    }

    public function testAStoredBatchKeepsItsSettingsAndStillSends(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $campaign->invitationExpiryPeriod = 'P14D';
        $this->saveTestElement($campaign, false, true, false);
        $recipient = $this->seedPendingRecipient($campaign);

        self::assertEqualsCanonicalizing(
            self::BATCH_SETTINGS,
            array_keys(get_object_vars($this->batch($campaign, [$recipient]))),
        );

        $stored = unserialize($this->storedBatch((int)$campaign->id, (int)$recipient->id));
        self::assertInstanceOf(SendBatchJob::class, $stored);
        $this->runBatch($stored);

        self::assertSame([$recipient->sms], $this->sentTo(self::SMS));
        self::assertSame([$recipient->email], $this->sentTo(self::EMAIL));
        self::assertNotNull($this->sendDate($recipient, self::SMS));
        self::assertNotNull($this->sendDate($recipient, self::EMAIL));
        self::assertSame([['smsSent' => 1, 'emailSent' => 1, 'errors' => 0]], $this->batchCounts($campaign));
        $persisted = $this->reloadRecipient($recipient);
        self::assertEquals((clone $persisted->smsSendDate)->add(new \DateInterval('P14D')), $persisted->invitationExpiryDate);
    }

    public function testBatchesQueuedByACampaignRunAndByAddingARecipientCarryOnlyTheBatchSettings(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $this->seedPendingRecipient($campaign);
        (new ProcessCampaignJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $this->siteId,
            'sendSms' => true,
            'sendEmail' => true,
        ]))->execute(Craft::$app->getQueue());

        $this->actAsUserRestrictedTo([$this->siteId], self::ADD_PERMISSIONS);
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$this->siteId,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sendInvitation' => '1',
        ]);
        try {
            $response = $this->recipientsController()->actionAdd();
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $this->ownRecipient((int)$recipientId);
            }
        }
        self::assertTrue($response->data['success']);

        $queued = $this->queuedJobs((int)$campaign->id);
        self::assertCount(2, $queued);
        foreach ($queued as $batch) {
            self::assertInstanceOf(SendBatchJob::class, $batch);
            self::assertEqualsCanonicalizing(self::BATCH_SETTINGS, array_keys(get_object_vars($batch)));
        }
    }

    /**
     * A batch for both channels exactly as the queue table stored it before
     * batches took a lock per invitation.
     */
    private function storedBatch(int $campaignId, int $recipientId): string
    {
        return sprintf(
            'O:47:"lindemannrock\campaignmanager\jobs\SendBatchJob":11:{'
            . 's:11:"description";N;'
            . 's:30:"%1$scraft\queue\BaseJob%1$s_progress";i:0;'
            . 's:35:"%1$scraft\queue\BaseJob%1$s_progressLabel";N;'
            . 's:10:"campaignId";i:%2$d;'
            . 's:6:"siteId";i:%3$d;'
            . 's:12:"recipientIds";a:1:{i:0;i:%4$d;}'
            . 's:7:"sendSms";b:1;'
            . 's:9:"sendEmail";b:1;'
            . 's:17:"triggeredByUserId";N;'
            . 's:24:"onlyWhileCampaignEnabled";b:0;'
            . 's:63:"%1$slindemannrock\campaignmanager\jobs\SendBatchJob%1$s_loggingHandle";s:16:"campaign-manager";'
            . '}',
            "\0",
            $campaignId,
            $this->siteId,
            $recipientId,
        );
    }
}
