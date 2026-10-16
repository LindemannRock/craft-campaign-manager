<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\tests\Stubs\RecordingProgressQueue;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Transport holds preserve pending SMS while email and progress finish.
 *
 * @since 5.16.0
 */
final class SendBatchJobSmsHoldTest extends InvitationDeliveryTestCase
{
    public static function ordinaryFailures(): iterable
    {
        yield 'invalid recipient' => ['invalid-recipient', 'recipient'];
        yield 'provider error' => ['provider-error', 'unknown'];
        yield 'unknown' => [null, null];
    }

    #[DataProvider('ordinaryFailures')]
    public function testOrdinaryFailureCountsAndContinues(?string $code, ?string $scope): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->smsProvider->answers = [['success' => false, 'failureCode' => $code, 'failureScope' => $scope, 'retryAfterSeconds' => null]];
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame(array_column($recipients, 'sms'), $this->sentTo(self::SMS));
        self::assertSame([['smsSent' => 1, 'emailSent' => 2, 'errors' => 1]], $this->batchCounts($campaign));
        self::assertNull($this->sendDate($recipients[0], self::SMS));
        self::assertNotNull($this->sendDate($recipients[1], self::SMS));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    public function testPartialSuccessAndAnOverlappingRunKeepOnlyUnsentSmsInTheContinuation(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->smsProvider->answers = [true, ['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 180]];
        $this->runBatch($this->batch($campaign, $recipients));
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$recipients[1]->id, (int)$recipients[2]->id], $queued[0]['job']->recipientIds);
        self::assertNotNull($this->sendDate($recipients[0], self::SMS));
        self::assertSame([['smsSent' => 1, 'emailSent' => 3, 'errors' => 0]], $this->batchCounts($campaign));
        // A separate supported run wins one pending recipient before the delayed job.
        $this->runBatch($this->channelBatch($campaign, [$recipients[1]], self::SMS));
        $this->runBatch(unserialize(serialize($queued[0]['job'])));
        self::assertSame([$recipients[0]->sms, $recipients[1]->sms, $recipients[1]->sms, $recipients[2]->sms], $this->sentTo(self::SMS));
        self::assertCount(3, $this->sentTo(self::EMAIL));
        foreach ($recipients as $recipient) {
            self::assertNotNull($this->sendDate($recipient, self::SMS));
            $this->assertDeliveryLockIsFree($recipient, self::SMS);
        }
    }

    public function testHeldSmsOnlyFinishesAndItsContinuationHonorsCampaignDisable(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $this->smsProvider->answers = [['success' => false, 'failureCode' => 'guard-busy', 'failureScope' => 'transport', 'retryAfterSeconds' => 1]];
        $batch = $this->batch($campaign, [$recipient], ['sendEmail' => false, 'onlyWhileCampaignEnabled' => true]);
        $this->runBatch($batch);
        $queued = $this->queuedJobs((int)$campaign->id);
        self::assertCount(1, $queued);
        $this->disableOwnedCampaignForSite($campaign, $this->siteId);
        $this->runBatch($queued[0]);
        self::assertCount(1, $this->sentTo(self::SMS));
        self::assertNull($this->sendDate($recipient, self::SMS));
        self::assertSame([['smsSent' => 0, 'emailSent' => 0, 'errors' => 0]], $this->batchCounts($campaign));
    }

    public function testContinuationRereadsPendingIdsAfterIndependentEmailProcessing(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->smsProvider->answers = [['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 120]];
        $this->duringTheFirstSend(self::EMAIL, function() use ($campaign, $recipients): void {
            // Another job sends the later recipient while this job processes email.
            $this->runBatch($this->channelBatch($campaign, [$recipients[1]], self::SMS));
        });
        $this->runBatch($this->batch($campaign, $recipients));
        $queued = $this->queuedJobs((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$recipients[0]->id], $queued[0]->recipientIds);
        self::assertNotNull($this->sendDate($recipients[1], self::SMS));
    }

    public function testAnsweredAndExpiredRecipientsStayOutOfHeldContinuation(): void
    {
        $clockPauses = 0;
        try {
            DateTimeHelper::pause(new DateTime('2026-10-01 12:00:00'));
            $clockPauses++;
            $campaign = $this->seedDeliverableCampaign();
            $campaign->invitationExpiryPeriod = 'P1D';
            $this->saveTestElement($campaign, false, true, false);
            $pending = $this->seedPendingRecipient($campaign);
            $answered = $this->seedPendingRecipient($campaign);
            $expired = $this->seedPendingRecipient($campaign);
            $this->runBatch($this->channelBatch($campaign, [$answered, $expired], self::EMAIL));
            $form = $campaign->getForm();
            self::assertNotNull($form);
            $this->submitForm($form, (string)$answered->emailInvitationCode, $this->siteId);
            self::assertTrue($this->reloadRecipient($answered)->hasSubmission());
            DateTimeHelper::pause(new DateTime('2026-10-03 12:00:00'));
            $clockPauses++;
            $this->smsProvider->answers = [['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 120]];
            $this->runBatch($this->batch($campaign, [$pending, $answered, $expired]));
            $queued = $this->queuedJobs((int)$campaign->id);
            self::assertCount(1, $queued);
            self::assertSame([(int)$pending->id], $queued[0]->recipientIds);
            self::assertSame([$pending->sms], $this->sentTo(self::SMS));
        } finally {
            while ($clockPauses-- > 0) {
                DateTimeHelper::resume();
            }
        }
    }

    public function testInvitationDelayKeepsEmailIndependentWithoutDuplicatingSmsContinuations(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $campaign->invitationDelayPeriod = 'PT1H';
        $this->saveTestElement($campaign, false, true, false);
        $ready = $this->seedPendingRecipient($campaign);
        $waiting = $this->seedPendingRecipient($campaign);
        $clockPauses = 0;
        try {
            DateTimeHelper::pause(new DateTime('2026-10-15 12:00:00'));
            $clockPauses++;
            foreach ([[$ready, '10:00:00'], [$waiting, '11:30:00']] as [$recipient, $time]) {
                Craft::$app->getDb()->createCommand()->update($recipient::tableName(), [
                    'dateCreated' => Db::prepareDateForDb(new DateTime('2026-10-15 ' . $time)),
                ], ['id' => $recipient->id])->execute();
            }
            $this->smsProvider->answers = [['success' => false, 'failureCode' => 'cooldown-active', 'failureScope' => 'transport', 'retryAfterSeconds' => 120]];
            $batch = $this->batch($campaign, [$waiting, $ready], ['onlyWhileCampaignEnabled' => true]);
            $queue = new RecordingProgressQueue();
            $batch->execute($queue);
            self::assertSame([50, 100], $queue->progress);
            $queued = $this->queuedJobsWithDelay((int)$campaign->id);
            self::assertCount(2, $queued);
            self::assertFalse($queued[0]['job']->sendSms);
            self::assertTrue($queued[0]['job']->sendEmail);
            self::assertSame([(int)$waiting->id], $queued[0]['job']->recipientIds);
            self::assertTrue($queued[1]['job']->sendSms);
            self::assertFalse($queued[1]['job']->sendEmail);
            self::assertSame([(int)$ready->id, (int)$waiting->id], $queued[1]['job']->recipientIds);
            self::assertSame(1800, $queued[1]['delay']);
            self::assertTrue($queued[1]['job']->onlyWhileCampaignEnabled);
            self::assertSame([$ready->email], $this->sentTo(self::EMAIL));
        } finally {
            while ($clockPauses-- > 0) {
                DateTimeHelper::resume();
            }
        }
    }

    /** @return array<string, array{string, string, ?int}> */
    public static function holds(): array
    {
        return [
            'account' => ['account-unavailable', 'account', null],
            'IP block' => ['ip-blocked', 'transport', 180],
            'timed cooldown' => ['cooldown-active', 'transport', 93],
            'busy' => ['guard-busy', 'transport', 1],
            'manual cooldown' => ['cooldown-active', 'account', null],
            'unavailable' => ['guard-unavailable', 'transport', null],
            'uncertain' => ['transport-uncertain', 'transport', null],
        ];
    }

    #[DataProvider('holds')]
    public function testHoldStopsSmsAndFinishesEmailWithOnlyThePermittedContinuation(string $code, string $scope, ?int $delay): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $user = $this->actAsUserRestrictedTo([$this->siteId], []);
        $this->smsProvider->answers = [['success' => false, 'failureCode' => $code, 'failureScope' => $scope, 'retryAfterSeconds' => $delay]];
        $queue = new RecordingProgressQueue();
        $batch = $this->batch($campaign, $recipients, ['triggeredByUserId' => (int)$user->id, 'onlyWhileCampaignEnabled' => true]);
        $batch->execute($queue);

        self::assertSame([$recipients[0]->sms], $this->sentTo(self::SMS));
        self::assertSame(array_column($recipients, 'email'), $this->sentTo(self::EMAIL));
        self::assertSame([33, 67, 100], $queue->progress);
        foreach ($recipients as $recipient) {
            self::assertNull($this->sendDate($recipient, self::SMS));
            self::assertNotNull($this->sendDate($recipient, self::EMAIL));
            $this->assertDeliveryLockIsFree($recipient, self::SMS);
        }
        self::assertSame([['smsSent' => 0, 'emailSent' => 3, 'errors' => 0]], $this->batchCounts($campaign));
        $details = $this->logDetails($this->batchLogs($campaign)[0]);
        self::assertSame(['siteId', 'siteName', 'recipientCount', 'smsSent', 'emailSent', 'errors', 'triggeredByUserId'], array_keys($details));
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount($delay === null ? 0 : 1, $queued);
        if ($delay !== null) {
            $continuation = $queued[0]['job'];
            self::assertInstanceOf(SendBatchJob::class, $continuation);
            self::assertSame($delay, $queued[0]['delay']);
            self::assertSame($batch->recipientIds, $continuation->recipientIds);
            self::assertSame($batch->campaignId, $continuation->campaignId);
            self::assertSame($batch->siteId, $continuation->siteId);
            self::assertSame($batch->triggeredByUserId, $continuation->triggeredByUserId);
            self::assertTrue($continuation->onlyWhileCampaignEnabled);
            self::assertTrue($continuation->sendSms);
            self::assertFalse($continuation->sendEmail);
            self::assertSame(array_keys(get_object_vars($batch)), array_keys(get_object_vars($continuation)));
        }
    }
}
