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
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batches that overlap on a recipient send that recipient's invitation once
 * per channel. A batch never sends an invitation another worker is sending
 * right now, nor one that was sent after the batch loaded its recipients;
 * it leaves both alone without counting a send or an error. The two
 * channels of a recipient do not hold each other back, and a worker that
 * dies mid-send does not keep later batches from sending.
 *
 * "Another worker" is either a second batch run while the first one's
 * message is on its way, or a lock held on a separate database connection,
 * which is what a second worker process is to the database.
 *
 * @since 5.16.0
 */
#[CoversClass(SendBatchJob::class)]
final class SendBatchJobOverlappingDeliveryTest extends InvitationDeliveryTestCase
{
    private const ADD_PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function heldAndFreeChannels(): array
    {
        return [
            'SMS held, email free' => [self::SMS, self::EMAIL],
            'email held, SMS free' => [self::EMAIL, self::SMS],
        ];
    }

    #[DataProvider('channels')]
    public function testABatchOverlappingAnotherDoesNotSendTheSameInvitationTwice(string $channel): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $overlapping = $this->channelBatch($campaign, [$recipient], $channel);
        $this->duringTheFirstSend($channel, fn() => $this->runBatch($overlapping));

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        self::assertSame([$this->contact($recipient, $channel)], $this->sentTo($channel));
        self::assertNotNull($this->sendDate($recipient, $channel));
        // The overlapping batch finished first: nothing sent, nothing failed
        self::assertSame(
            [$this->counts($channel, 0), $this->counts($channel, 1)],
            $this->batchCounts($campaign),
        );
        $this->assertDeliveryLockIsFree($recipient, $channel);
    }

    #[DataProvider('channels')]
    public function testAnInvitationAnotherWorkerIsSendingIsLeftAloneAndSentOnceLater(string $channel): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $lock = $this->deliveryLockName($recipient, $channel);
        $worker = $this->anotherWorker();
        self::assertTrue($worker->acquire($lock));

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        self::assertSame([], $this->sentTo($channel));
        self::assertNull($this->sendDate($recipient, $channel));
        self::assertSame([$this->counts($channel, 0)], $this->batchCounts($campaign));

        $worker->release($lock);
        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        self::assertSame([$this->contact($recipient, $channel)], $this->sentTo($channel));
        self::assertNotNull($this->sendDate($recipient, $channel));
        self::assertSame(
            [$this->counts($channel, 0), $this->counts($channel, 1)],
            $this->batchCounts($campaign),
        );
        $this->assertDeliveryLockIsFree($recipient, $channel);
    }

    #[DataProvider('heldAndFreeChannels')]
    public function testAChannelAnotherWorkerIsSendingDoesNotHoldBackTheOtherChannel(string $held, string $free): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $worker = $this->anotherWorker();
        self::assertTrue($worker->acquire($this->deliveryLockName($recipient, $held)));

        $this->runBatch($this->batch($campaign, [$recipient]));

        self::assertSame([], $this->sentTo($held));
        self::assertNull($this->sendDate($recipient, $held));
        self::assertSame([$this->contact($recipient, $free)], $this->sentTo($free));
        self::assertNotNull($this->sendDate($recipient, $free));
        self::assertSame([$this->counts($free, 1)], $this->batchCounts($campaign));
        $this->assertDeliveryLockIsFree($recipient, $free);
    }

    #[DataProvider('channels')]
    public function testAnInvitationSentAfterTheBatchLoadedItsRecipientsIsNotSentAgain(string $channel): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $first = $this->seedPendingRecipient($campaign);
        $second = $this->seedPendingRecipient($campaign);
        // Both recipients are still pending when the batch loads them. While
        // it sends to the first, another batch sends to the second.
        $other = $this->channelBatch($campaign, [$second], $channel);
        $this->duringTheFirstSend($channel, fn() => $this->runBatch($other));

        $this->runBatch($this->channelBatch($campaign, [$first, $second], $channel));

        self::assertSame(
            [$this->contact($first, $channel), $this->contact($second, $channel)],
            $this->sentTo($channel),
        );
        self::assertNotNull($this->sendDate($first, $channel));
        self::assertNotNull($this->sendDate($second, $channel));
        self::assertSame(
            [$this->counts($channel, 1), $this->counts($channel, 1)],
            $this->batchCounts($campaign),
        );
        $this->assertDeliveryLockIsFree($second, $channel);
    }

    #[DataProvider('channels')]
    public function testAWorkerThatDiesWhileSendingDoesNotKeepLaterBatchesFromSending(string $channel): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $lock = $this->deliveryLockName($recipient, $channel);
        $worker = $this->anotherWorker();
        $witness = $this->anotherWorker();
        self::assertTrue($worker->acquire($lock));
        self::assertFalse($witness->acquire($lock), 'A living worker keeps its lock.');

        $worker->dieHoldingItsLocks();

        // Nobody released the lock: the database frees it when the worker's
        // connection ends, and the wait for that happens in the database.
        self::assertTrue($witness->acquire($lock, 30), 'The lock outlived the connection that held it.');
        $witness->release($lock);

        $this->runBatch($this->channelBatch($campaign, [$recipient], $channel));

        self::assertSame([$this->contact($recipient, $channel)], $this->sentTo($channel));
        self::assertNotNull($this->sendDate($recipient, $channel));
        self::assertSame([$this->counts($channel, 1)], $this->batchCounts($campaign));
    }

    public function testBatchesQueuedByOverlappingCampaignRunsSendEachInvitationOnce(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $first = $this->seedPendingRecipient($campaign);
        $second = $this->seedPendingRecipient($campaign);
        // The campaign is run twice before any batch has been worked off
        $this->runCampaign((int)$campaign->id);
        $this->runCampaign((int)$campaign->id);
        [$batch, $overlapping] = $this->queuedSendBatches((int)$campaign->id, 2);
        self::assertSame($batch->recipientIds, $overlapping->recipientIds);
        $this->duringTheFirstSend(self::SMS, fn() => $this->runBatch($overlapping));

        $this->runBatch($batch);

        self::assertEqualsCanonicalizing([$first->sms, $second->sms], $this->sentTo(self::SMS));
        self::assertEqualsCanonicalizing([$first->email, $second->email], $this->sentTo(self::EMAIL));
        self::assertSame(
            ['smsSent' => 2, 'emailSent' => 2, 'errors' => 0],
            $this->totalCounts($this->batchCounts($campaign)),
        );
    }

    public function testAnImportBatchOverlappingACampaignRunSendsEachInvitationOnce(): void
    {
        $campaign = $this->seedDeliverableCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        // The settings an import queues its batches with
        $imported = $this->batch($campaign, [$recipient], ['onlyWhileCampaignEnabled' => true]);
        $this->runCampaign((int)$campaign->id);
        [$campaignRun] = $this->queuedSendBatches((int)$campaign->id, 1);
        $this->duringTheFirstSend(self::SMS, fn() => $this->runBatch($campaignRun));

        $this->runBatch($imported);

        self::assertSame([$recipient->sms], $this->sentTo(self::SMS));
        self::assertSame([$recipient->email], $this->sentTo(self::EMAIL));
        self::assertSame(
            ['smsSent' => 1, 'emailSent' => 1, 'errors' => 0],
            $this->totalCounts($this->batchCounts($campaign)),
        );
    }

    public function testARecipientAddedWithAnInvitationIsNotSentItAgainByAnOverlappingCampaignRun(): void
    {
        $campaign = $this->seedDeliverableCampaign();
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

        $this->runCampaign((int)$campaign->id);
        [$added, $campaignRun] = $this->queuedSendBatches((int)$campaign->id, 2);
        self::assertSame($added->recipientIds, $campaignRun->recipientIds);
        $this->duringTheFirstSend(self::EMAIL, fn() => $this->runBatch($campaignRun));

        $this->runBatch($added);

        self::assertSame([$email], $this->sentTo(self::EMAIL));
        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame(
            ['smsSent' => 0, 'emailSent' => 1, 'errors' => 0],
            $this->totalCounts($this->batchCounts($campaign)),
        );
    }

    /**
     * Run the campaign for the test's site the way the run action queues it.
     */
    private function runCampaign(int $campaignId): void
    {
        (new ProcessCampaignJob([
            'campaignId' => $campaignId,
            'siteId' => $this->siteId,
            'sendSms' => true,
            'sendEmail' => true,
        ]))->execute(Craft::$app->getQueue());
    }

    /**
     * The send batches queued for the campaign, read back from the queue as
     * a worker receives them, oldest first.
     *
     * @return list<SendBatchJob>
     */
    private function queuedSendBatches(int $campaignId, int $expected): array
    {
        $batches = [];
        foreach ($this->queuedJobs($campaignId) as $job) {
            self::assertInstanceOf(SendBatchJob::class, $job);
            $batches[] = $job;
        }
        self::assertCount($expected, $batches);

        return $batches;
    }

    /**
     * @param list<array{smsSent: int, emailSent: int, errors: int}> $counts
     * @return array{smsSent: int, emailSent: int, errors: int}
     */
    private function totalCounts(array $counts): array
    {
        return [
            'smsSent' => array_sum(array_column($counts, 'smsSent')),
            'emailSent' => array_sum(array_column($counts, 'emailSent')),
            'errors' => array_sum(array_column($counts, 'errors')),
        ];
    }
}
