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
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\InvitationDeliveryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A batch that runs before a recipient has waited the campaign's Invitation
 * Delay does not send to that recipient. The delay is read from the
 * campaign when the batch runs, so running early, a changed delay, a retry
 * or an overlapping batch cannot send early. The recipients still waiting
 * are queued again, with the batch's own settings, to run once the last of
 * them is eligible, and once they are, each is sent to exactly once. A
 * stored delay that cannot be read fails the batch before anything is
 * attempted. The clock is held still so the waits are exact; nothing
 * leaves the process.
 *
 * @since 5.16.0
 */
#[CoversClass(SendBatchJob::class)]
final class SendBatchJobInvitationDelayTest extends InvitationDeliveryTestCase
{
    /**
     * The moment the tests happen at.
     */
    private const NOW = '2026-03-10 09:00:00';

    /**
     * A delay the campaign edit form once accepted but PHP cannot read as a
     * duration, as found stored on a real campaign.
     */
    private const UNREADABLE_DELAY = 'PT213213213213123H';

    private int $clockPauses = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->holdClockAt(new DateTime(self::NOW));
    }

    protected function cleanupExternalState(): void
    {
        while ($this->clockPauses > 0) {
            DateTimeHelper::resume();
            $this->clockPauses--;
        }
        parent::cleanupExternalState();
    }

    public function testARecipientStillInsideTheDelayIsNotSentToAndIsQueuedAgain(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        $batch = $this->batch($campaign, [$recipient]);
        $logsBefore = $this->ownedActivityLogCount();

        $this->runBatch($batch);

        self::assertSame([], $this->sentTo(self::SMS), 'No SMS may leave before the delay has passed.');
        self::assertSame([], $this->sentTo(self::EMAIL), 'No email may leave before the delay has passed.');
        self::assertNull($this->sendDate($recipient, self::SMS));
        self::assertNull($this->sendDate($recipient, self::EMAIL));
        $this->assertDeliveryLockIsFree($recipient, self::SMS);
        $this->assertDeliveryLockIsFree($recipient, self::EMAIL);
        self::assertSame($logsBefore, $this->ownedActivityLogCount(), 'A batch that sent nothing and failed nothing writes no batch log.');

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$recipient->id], $queued[0]['job']->recipientIds);
        self::assertSame($this->secondsIn('PT2H'), $queued[0]['delay'], 'The recipient is queued again for when the delay has passed.');
    }

    public function testARecipientPastTheDelayIsSentTo(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->addedAgo($this->seedPendingRecipient($campaign), 'PT3H');

        $this->runBatch($this->batch($campaign, [$recipient]));

        self::assertSame([$this->contact($recipient, self::SMS)], $this->sentTo(self::SMS));
        self::assertSame([$this->contact($recipient, self::EMAIL)], $this->sentTo(self::EMAIL));
        self::assertNotNull($this->sendDate($recipient, self::SMS));
        self::assertNotNull($this->sendDate($recipient, self::EMAIL));
        self::assertSame([], $this->queuedJobs((int)$campaign->id), 'Nothing is left to queue again.');
        self::assertSame([['smsSent' => 1, 'emailSent' => 1, 'errors' => 0]], $this->batchCounts($campaign));
    }

    public function testADelayLengthenedSinceTheBatchWasQueuedIsHonoured(): void
    {
        $campaign = $this->seedDelayedCampaign('PT1H');
        $recipient = $this->addedAgo($this->seedPendingRecipient($campaign), 'PT2H');
        $batch = $this->batch($campaign, [$recipient]);
        $this->setDelay($campaign, 'P1D');

        $this->runBatch($batch);

        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame([], $this->sentTo(self::EMAIL));
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame($this->secondsIn('P1D') - $this->secondsIn('PT2H'), $queued[0]['delay'], 'The recipient waits for the rest of the new delay.');
    }

    public function testADelayShortenedSinceTheBatchWasQueuedSendsNow(): void
    {
        $campaign = $this->seedDelayedCampaign('P1D');
        $recipient = $this->addedAgo($this->seedPendingRecipient($campaign), 'PT2H');
        $batch = $this->batch($campaign, [$recipient]);
        $this->setDelay($campaign, 'PT1H');

        $this->runBatch($batch);

        self::assertSame([$this->contact($recipient, self::SMS)], $this->sentTo(self::SMS));
        self::assertSame([$this->contact($recipient, self::EMAIL)], $this->sentTo(self::EMAIL));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    public function testADelayRemovedSinceTheBatchWasQueuedSendsNow(): void
    {
        $campaign = $this->seedDelayedCampaign('P1D');
        $recipient = $this->seedPendingRecipient($campaign);
        $batch = $this->batch($campaign, [$recipient]);
        $this->setDelay($campaign, null);

        $this->runBatch($batch);

        self::assertSame([$this->contact($recipient, self::EMAIL)], $this->sentTo(self::EMAIL));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    public function testAMixedBatchSendsToTheEligibleAndQueuesTheRestAgain(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $eligible = $this->addedAgo($this->seedPendingRecipient($campaign), 'PT3H');
        $waiting = $this->seedPendingRecipient($campaign);
        $user = $this->actAsUserRestrictedTo([$this->siteId], []);

        $this->runBatch($this->batch($campaign, [$waiting, $eligible], ['triggeredByUserId' => (int)$user->id]));

        self::assertSame([$this->contact($eligible, self::SMS)], $this->sentTo(self::SMS));
        self::assertSame([$this->contact($eligible, self::EMAIL)], $this->sentTo(self::EMAIL));
        self::assertNull($this->sendDate($waiting, self::SMS));
        self::assertNull($this->sendDate($waiting, self::EMAIL));

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$waiting->id], $queued[0]['job']->recipientIds, 'Only the recipient still waiting is queued again.');
        self::assertSame($this->secondsIn('PT2H'), $queued[0]['delay']);

        $logs = $this->batchLogs($campaign);
        self::assertCount(1, $logs);
        $details = $this->logDetails($logs[0]);
        self::assertSame(2, $details['recipientCount']);
        self::assertSame([1, 1, 0, 1], [$details['smsSent'], $details['emailSent'], $details['errors'], $details['waiting']]);
    }

    public function testTheRecipientsQueuedAgainKeepTheBatchSettings(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        $user = $this->actAsUserRestrictedTo([$this->siteId], []);
        $batch = $this->batch($campaign, [$recipient], [
            'sendSms' => false,
            'sendEmail' => true,
            'triggeredByUserId' => (int)$user->id,
            'onlyWhileCampaignEnabled' => true,
        ]);

        $this->runBatch($batch);

        $queued = $this->queuedJobs((int)$campaign->id);
        self::assertCount(1, $queued);
        $again = $queued[0];
        self::assertInstanceOf(SendBatchJob::class, $again);
        self::assertSame((int)$campaign->id, $again->campaignId);
        self::assertSame($this->siteId, $again->siteId);
        self::assertFalse($again->sendSms);
        self::assertTrue($again->sendEmail);
        self::assertSame((int)$user->id, $again->triggeredByUserId);
        self::assertTrue($again->onlyWhileCampaignEnabled);
        // A batch carries the same settings as before the delay existed, so
        // batches queued earlier run unchanged
        self::assertEqualsCanonicalizing(
            ['description', 'campaignId', 'siteId', 'recipientIds', 'sendSms', 'sendEmail', 'triggeredByUserId', 'onlyWhileCampaignEnabled'],
            array_keys(get_object_vars($again)),
        );
    }

    public function testOnceTheDelayHasPassedTheRecipientIsSentToExactlyOnce(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->runBatch($this->batch($campaign, [$recipient]));
        $again = $this->queuedJobs((int)$campaign->id)[0];
        self::assertInstanceOf(SendBatchJob::class, $again);

        $this->holdClockAt(DateTimeHelper::now()->add(new DateInterval('PT2H')));
        $this->runBatch($again);
        // The queue hands the same batch out again, as after a worker crash
        $this->runBatch($again);

        self::assertSame([$this->contact($recipient, self::SMS)], $this->sentTo(self::SMS), 'Sent once, when eligible.');
        self::assertSame([$this->contact($recipient, self::EMAIL)], $this->sentTo(self::EMAIL));
        self::assertCount(1, $this->queuedJobs((int)$campaign->id), 'Nothing more was queued.');
    }

    public function testOverlappingBatchesOnAWaitingRecipientDeliverOnceWhenEligible(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        $this->runBatch($this->batch($campaign, [$recipient]));
        $this->runBatch($this->batch($campaign, [$recipient]));
        $again = $this->queuedJobs((int)$campaign->id);
        self::assertCount(2, $again, 'Each early batch queues the recipient again.');

        $this->holdClockAt(DateTimeHelper::now()->add(new DateInterval('PT2H')));
        foreach ($again as $batch) {
            self::assertInstanceOf(SendBatchJob::class, $batch);
            $this->runBatch($batch);
        }

        self::assertSame([$this->contact($recipient, self::SMS)], $this->sentTo(self::SMS), 'Two batches, one delivery.');
        self::assertSame([$this->contact($recipient, self::EMAIL)], $this->sentTo(self::EMAIL));
    }

    public function testAFailedBatchStillQueuesTheWaitingRecipientsAgain(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $failing = $this->addedAgo($this->seedPendingRecipient($campaign), 'PT3H');
        $waiting = $this->seedPendingRecipient($campaign);
        $this->answerSendsWith(self::SMS, [false]);
        $this->answerSendsWith(self::EMAIL, [false]);

        try {
            $this->runBatch($this->batch($campaign, [$failing, $waiting]));
            self::fail('A batch that sent nothing and failed is reported to the queue.');
        } catch (SendBatchFailedException) {
        }

        self::assertSame([$this->contact($failing, self::SMS)], $this->sentTo(self::SMS), 'Only the eligible recipient was attempted.');
        self::assertSame([$this->contact($failing, self::EMAIL)], $this->sentTo(self::EMAIL));
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$waiting->id], $queued[0]['job']->recipientIds);
        self::assertSame($this->secondsIn('PT2H'), $queued[0]['delay']);
        self::assertSame([['smsSent' => 0, 'emailSent' => 0, 'errors' => 2]], $this->batchCounts($campaign), 'Each failed channel counts, as before.');
    }

    public function testABatchOfADisabledCampaignNeitherSendsNorQueuesAgain(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        $batch = $this->batch($campaign, [$recipient], ['onlyWhileCampaignEnabled' => true]);
        $this->disableOwnedCampaignForSite($campaign, $this->siteId);

        $this->runBatch($batch);

        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame([], $this->sentTo(self::EMAIL));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    public function testAStoredBatchOfACampaignWhoseDelayCannotBeReadSendsNothingAndFails(): void
    {
        $campaign = $this->seedDelayedCampaign(null);
        $recipient = $this->addedAgo($this->seedPendingRecipient($campaign), 'P30D');
        $batch = $this->batch($campaign, [$recipient]);
        $this->storeDelayAsFound($campaign, self::UNREADABLE_DELAY);
        $logsBefore = $this->ownedActivityLogCount();

        try {
            $this->runBatch($batch);
            self::fail('A batch whose delay cannot be read is reported to the queue as failed.');
        } catch (SendBatchFailedException) {
        }

        self::assertSame([], $this->sentTo(self::SMS), 'No SMS may leave while the delay cannot be read.');
        self::assertSame([], $this->sentTo(self::EMAIL), 'No email may leave while the delay cannot be read.');
        self::assertNull($this->sendDate($recipient, self::SMS));
        self::assertNull($this->sendDate($recipient, self::EMAIL));
        $this->assertDeliveryLockIsFree($recipient, self::SMS);
        $this->assertDeliveryLockIsFree($recipient, self::EMAIL);
        self::assertSame([], $this->queuedJobs((int)$campaign->id), 'The recipient is not queued again; an administrator has to correct the campaign first.');
        self::assertSame($logsBefore, $this->ownedActivityLogCount(), 'Nothing was attempted, so no batch log is written.');
        self::assertSame(self::UNREADABLE_DELAY, $this->storedDelay($campaign), 'The stored delay is left for an administrator to correct.');
    }

    public function testARecipientWithNothingLeftToSendIsNotQueuedAgain(): void
    {
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipient = $this->seedPendingRecipient($campaign);
        Craft::$app->getDb()->createCommand()->update(RecipientRecord::tableName(), [
            'smsSendDate' => Db::prepareDateForDb(DateTimeHelper::now()),
            'emailSendDate' => Db::prepareDateForDb(DateTimeHelper::now()),
        ], ['id' => $recipient->id])->execute();

        $this->runBatch($this->batch($campaign, [$recipient]));

        self::assertSame([], $this->sentTo(self::SMS));
        self::assertSame([], $this->sentTo(self::EMAIL));
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    private function seedDelayedCampaign(?string $delayPeriod): Campaign
    {
        $campaign = $this->seedDeliverableCampaign();
        $this->setDelay($campaign, $delayPeriod);

        return $campaign;
    }

    private function setDelay(Campaign $campaign, ?string $delayPeriod): void
    {
        $campaign->invitationDelayPeriod = $delayPeriod;
        $this->saveTestElement($campaign, false, true, false);
    }

    /**
     * Store a delay on the campaign exactly as a pre-existing database row
     * carries it, bypassing validation, which no longer accepts it.
     */
    private function storeDelayAsFound(Campaign $campaign, string $delayPeriod): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(CampaignRecord::tableName(), ['invitationDelayPeriod' => $delayPeriod], ['id' => $campaign->id])
            ->execute();
        self::assertSame($delayPeriod, $this->storedDelay($campaign));
    }

    /**
     * The campaign's delay as stored.
     */
    private function storedDelay(Campaign $campaign): ?string
    {
        $stored = (new Query())
            ->select('invitationDelayPeriod')
            ->from(CampaignRecord::tableName())
            ->where(['id' => $campaign->id])
            ->scalar();

        return $stored === false || $stored === null ? null : (string)$stored;
    }

    /**
     * Move the recipient's creation time the given period into the past.
     */
    private function addedAgo(RecipientRecord $recipient, string $period): RecipientRecord
    {
        $addedAt = DateTimeHelper::now()->sub(new DateInterval($period));
        Craft::$app->getDb()->createCommand()
            ->update(RecipientRecord::tableName(), ['dateCreated' => Db::prepareDateForDb($addedAt)], ['id' => $recipient->id])
            ->execute();

        return $this->reloadRecipient($recipient);
    }

    /**
     * How many seconds the period lasts from now.
     */
    private function secondsIn(string $period): int
    {
        $now = DateTimeHelper::now();

        return (clone $now)->add(new DateInterval($period))->getTimestamp() - $now->getTimestamp();
    }

    private function holdClockAt(DateTime $moment): void
    {
        DateTimeHelper::pause($moment);
        $this->clockPauses++;
    }
}
