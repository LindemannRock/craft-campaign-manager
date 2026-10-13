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
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\helpers\InvitationScheduleHelper;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A campaign's Invitation Delay holds every invitation back until the
 * recipient has been in the campaign for that long. Adding a recipient
 * with "Send Invitation", importing recipients with sending on, and running
 * the campaign all queue their batches so that no batch may run before its
 * recipients are eligible; a campaign without a delay queues them to run
 * right away. A stored delay that cannot be read holds everything: nothing
 * is queued until an administrator corrects the campaign. The clock is held
 * still so the waits are exact.
 *
 * @since 5.16.0
 */
#[CoversClass(RecipientsController::class)]
#[CoversClass(ProcessCampaignJob::class)]
#[CoversClass(InvitationScheduleHelper::class)]
final class InvitationDelayQueueingTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;

    private const ADD_PERMISSIONS = ['campaignManager:manageRecipients', 'campaignManager:addRecipients'];

    private const IMPORT_PERMISSIONS = ['campaignManager:manageRecipients', 'campaignManager:importRecipients'];

    /**
     * The moment the tests happen at.
     */
    private const NOW = '2026-03-10 09:00:00';

    /**
     * A delay the campaign edit form once accepted but PHP cannot read as a
     * duration, as found stored on a real campaign.
     */
    private const UNREADABLE_DELAY = 'PT213213213213123H';

    /**
     * What the administrator is told when a recipient was added but the
     * requested invitation could not be queued.
     */
    private const ADD_NOT_QUEUED_WARNING = 'Recipient added, but the invitation was not queued: the campaign\'s invitation delay is invalid. Correct the delay on the campaign, then send the invitation by running the campaign.';

    /**
     * What the administrator is told when recipients were imported but the
     * requested invitations could not be queued.
     */
    private const IMPORT_NOT_QUEUED_WARNING = 'Invitations were not queued: the campaign\'s invitation delay is invalid. Correct the delay on the campaign, then send the invitations by running the campaign.';

    private int $clockPauses = 0;

    /**
     * The JSON answer of the last Add Recipient request.
     *
     * @var array<string, mixed>
     */
    private array $lastAddResponse = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
        $this->holdClockAt(new DateTime(self::NOW));
    }

    protected function cleanupExternalState(): void
    {
        while ($this->clockPauses > 0) {
            DateTimeHelper::resume();
            $this->clockPauses--;
        }
        $this->dropImportSession();
        parent::cleanupExternalState();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function delayPeriods(): iterable
    {
        yield 'two hours' => ['PT2H'];
        yield 'one day' => ['P1D'];
        yield 'two weeks' => ['P2W'];
        yield 'one calendar month' => ['P1M'];
    }

    #[DataProvider('delayPeriods')]
    public function testAddingARecipientWithSendInvitationQueuesItAfterTheDelay(string $period): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign($period);
        $this->actAsUserRestrictedTo([$siteA], self::ADD_PERMISSIONS);

        $recipientId = $this->addRecipientWithSendInvitation($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertInstanceOf(SendBatchJob::class, $queued[0]['job']);
        self::assertSame([$recipientId], $queued[0]['job']->recipientIds);
        self::assertSame($this->secondsIn($period), $queued[0]['delay'], 'The batch may not run before the recipient has waited the whole delay.');
    }

    public function testAddingARecipientToACampaignWithoutADelayQueuesItNow(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->actAsUserRestrictedTo([$siteA], self::ADD_PERMISSIONS);

        $recipientId = $this->addRecipientWithSendInvitation($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([$recipientId], $queued[0]['job']->recipientIds);
        self::assertSame(0, $queued[0]['delay']);
    }

    public function testImportedRecipientsAreQueuedAfterTheDelay(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('P1D');
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, 2);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertInstanceOf(SendBatchJob::class, $queued[0]['job']);
        self::assertSame($created, $queued[0]['job']->recipientIds);
        self::assertSame($this->secondsIn('P1D'), $queued[0]['delay']);
    }

    public function testImportedRecipientsOfACampaignWithoutADelayAreQueuedNow(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, 2);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame($created, $queued[0]['job']->recipientIds);
        self::assertSame(0, $queued[0]['delay']);
    }

    public function testRunningACampaignQueuesEligibleRecipientsNowAndTheRestWhenTheyAreEligible(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('P1D');
        $old = $this->seedRecipientAddedAgo($campaign, $siteA, 'P2D');
        $recent = $this->seedRecipientAddedAgo($campaign, $siteA, 'PT12H');
        $new = $this->seedRecipientAddedAgo($campaign, $siteA, null);

        $this->runCampaign($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(2, $queued);
        self::assertSame([(int)$old->id], $queued[0]['job']->recipientIds, 'The recipient who has waited long enough is sent to now.');
        self::assertSame(0, $queued[0]['delay']);
        self::assertSame([(int)$recent->id, (int)$new->id], $queued[1]['job']->recipientIds, 'The others wait together, in the order they become eligible.');
        self::assertSame($this->secondsIn('P1D'), $queued[1]['delay'], 'The batch may not run before its newest recipient is eligible.');
        self::assertGreaterThan($this->secondsIn('PT12H'), $queued[1]['delay']);
    }

    public function testRunningACampaignWithoutADelayQueuesEveryoneNow(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $old = $this->seedRecipientAddedAgo($campaign, $siteA, 'P2D');
        $new = $this->seedRecipientAddedAgo($campaign, $siteA, null);

        $this->runCampaign($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame([(int)$old->id, (int)$new->id], $queued[0]['job']->recipientIds);
        self::assertSame(0, $queued[0]['delay']);
    }

    public function testWaitingRecipientsAreBatchedInTheOrderTheyBecomeEligible(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('PT2H');
        $recipients = [];
        // Added one minute apart, newest first, so the queue must put them in order
        for ($minutesAgo = ProcessCampaignJob::BATCH_SIZE; $minutesAgo >= 0; $minutesAgo--) {
            $recipients[$minutesAgo] = (int)$this->seedRecipientAddedAgo($campaign, $siteA, $minutesAgo > 0 ? 'PT' . $minutesAgo . 'M' : null)->id;
        }
        krsort($recipients);
        $expectedOrder = array_values($recipients);

        $this->runCampaign($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(2, $queued);
        self::assertSame(array_slice($expectedOrder, 0, ProcessCampaignJob::BATCH_SIZE), $queued[0]['job']->recipientIds);
        self::assertSame($this->secondsIn('PT2H') - 60, $queued[0]['delay'], 'The first batch waits for its last recipient, added a minute ago.');
        self::assertSame(array_slice($expectedOrder, ProcessCampaignJob::BATCH_SIZE), $queued[1]['job']->recipientIds);
        self::assertSame($this->secondsIn('PT2H'), $queued[1]['delay'], 'The second batch waits for the recipient added just now.');
    }

    public function testADelayLongerThanTheQueueAcceptsIsQueuedWithTheLongestWait(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('PT1000000H');
        $this->seedRecipientAddedAgo($campaign, $siteA, null);

        $this->runCampaign($campaign, $siteA);

        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame(InvitationScheduleHelper::MAX_QUEUE_DELAY_SECONDS, $queued[0]['delay']);
    }

    public function testAddingARecipientWithSendInvitationToACampaignWhoseDelayCannotBeReadQueuesNothing(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->storeDelayAsFound($campaign, self::UNREADABLE_DELAY);
        $this->actAsUserRestrictedTo([$siteA], self::ADD_PERMISSIONS);

        $recipientId = $this->addRecipientWithSendInvitation($campaign, $siteA);

        self::assertSame([], $this->queuedJobsWithDelay((int)$campaign->id), 'An invitation whose delay cannot be read is not queued at all.');
        $this->assertRecipientIsStillPending($recipientId);
        self::assertSame(self::UNREADABLE_DELAY, $this->storedDelay($campaign), 'The stored delay is left for an administrator to correct.');
        self::assertSame(self::ADD_NOT_QUEUED_WARNING, $this->lastAddResponse['warning'] ?? null, 'A JSON caller is told that the invitation was not queued.');
    }

    public function testAddingARecipientFromTheFormTellsTheAdministratorTheInvitationWasNotQueued(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->storeDelayAsFound($campaign, self::UNREADABLE_DELAY);
        $this->actAsUserRestrictedTo([$siteA], self::ADD_PERMISSIONS);

        $recipientId = $this->addRecipientWithSendInvitationFromTheForm($campaign, $siteA);

        self::assertSame([], $this->queuedJobsWithDelay((int)$campaign->id));
        $this->assertRecipientIsStillPending($recipientId);
        self::assertSame(self::ADD_NOT_QUEUED_WARNING, $this->session()->getError(), 'The form submission is answered with a visible warning.');
        self::assertNull($this->session()->getNotice());
    }

    public function testAddingARecipientWithAReadableDelayRaisesNoWarning(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('P1D');
        $this->actAsUserRestrictedTo([$siteA], self::ADD_PERMISSIONS);

        $this->addRecipientWithSendInvitationFromTheForm($campaign, $siteA);

        self::assertCount(1, $this->queuedJobsWithDelay((int)$campaign->id));
        self::assertNull($this->session()->getError());
    }

    public function testImportedRecipientsOfACampaignWhoseDelayCannotBeReadAreNotQueued(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->storeDelayAsFound($campaign, self::UNREADABLE_DELAY);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, 2);

        self::assertCount(2, $created, 'The recipients are imported; only the sending is held.');
        self::assertSame([], $this->queuedJobsWithDelay((int)$campaign->id));
        self::assertNull($this->latestOwnedLog('campaign_invitations_queued'), 'Nothing was queued, so no queueing is logged.');
        foreach ($created as $recipientId) {
            $this->assertRecipientIsStillPending($recipientId);
        }
        self::assertSame(self::UNREADABLE_DELAY, $this->storedDelay($campaign));
        self::assertStringContainsString('Successfully imported 2 recipient(s).', (string)$this->session()->getNotice());
        self::assertStringNotContainsString('Invitation sending has been queued.', (string)$this->session()->getNotice());
        self::assertSame(self::IMPORT_NOT_QUEUED_WARNING, $this->session()->getError(), 'The import result tells the administrator that no invitation was queued.');
    }

    public function testImportingIntoACampaignWithAReadableDelayRaisesNoWarning(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign('P1D');
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $this->importRows($campaign, $siteA, 1);

        self::assertCount(1, $this->queuedJobsWithDelay((int)$campaign->id));
        self::assertStringContainsString('Invitation sending has been queued.', (string)$this->session()->getNotice());
        self::assertNull($this->session()->getError());
    }

    public function testRunningACampaignWhoseDelayCannotBeReadQueuesNothingAndFails(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDelayedCampaign(null);
        $this->storeDelayAsFound($campaign, self::UNREADABLE_DELAY);
        $old = $this->seedRecipientAddedAgo($campaign, $siteA, 'P2D');
        $new = $this->seedRecipientAddedAgo($campaign, $siteA, null);

        try {
            $this->runCampaign($campaign, $siteA);
            self::fail('A campaign run whose delay cannot be read is reported to the queue as failed.');
        } catch (SendBatchFailedException $e) {
            self::assertSame(
                'Campaign ' . $campaign->id . ' could not be run: its invitation delay is invalid and no invitations were queued. Correct the delay on the campaign, then run it again.',
                $e->getMessage(),
                'The queue names the invalid delay; no batch exists that could have failed.',
            );
            self::assertStringNotContainsString('batch failed', $e->getMessage());
        }

        self::assertSame([], $this->queuedJobsWithDelay((int)$campaign->id), 'No batch is queued, however long a recipient has waited.');
        self::assertNull($this->latestOwnedLog('campaign_batches_queued'));
        $this->assertRecipientIsStillPending((int)$old->id);
        $this->assertRecipientIsStillPending((int)$new->id);
        self::assertSame(self::UNREADABLE_DELAY, $this->storedDelay($campaign));
    }

    /**
     * A campaign with both invitation messages and the given delay, enabled
     * on every site.
     */
    private function seedDelayedCampaign(?string $delayPeriod): Campaign
    {
        $campaign = $this->seedOwnedCampaign();
        $campaign->invitationDelayPeriod = $delayPeriod;
        $campaign->emailInvitationMessage = 'Please take part.';
        $campaign->smsInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
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
     * The recipient exists and has been sent nothing on either channel.
     */
    private function assertRecipientIsStillPending(int $recipientId): void
    {
        $recipient = RecipientRecord::findOne($recipientId);
        self::assertInstanceOf(RecipientRecord::class, $recipient);
        self::assertNull($recipient->smsSendDate);
        self::assertNull($recipient->emailSendDate);
    }

    /**
     * A recipient added the given time before now, or right now.
     */
    private function seedRecipientAddedAgo(Campaign $campaign, int $siteId, ?string $period): RecipientRecord
    {
        $addedAt = DateTimeHelper::now();
        if ($period !== null) {
            $addedAt = $addedAt->sub(new DateInterval($period));
        }

        return $this->seedOwnedRecipient($campaign, $siteId, ['dateCreated' => Db::prepareDateForDb($addedAt)]);
    }

    /**
     * Post the Add Recipient form with "Send Invitation" ticked.
     */
    private function addRecipientWithSendInvitation(Campaign $campaign, int $siteId): int
    {
        $email = $this->requestAddRecipientWithSendInvitation($campaign, $siteId, true);

        $created = [];
        try {
            $this->lastAddResponse = $this->withSession(fn() => $this->recipientsController()->actionAdd())->data;
            self::assertTrue($this->lastAddResponse['success']);
        } finally {
            $created = $this->ownRecipientsWithEmail($email);
        }
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * Post the Add Recipient form with "Send Invitation" ticked the way the
     * control panel form does, expecting a redirect rather than JSON.
     */
    private function addRecipientWithSendInvitationFromTheForm(Campaign $campaign, int $siteId): int
    {
        $email = $this->requestAddRecipientWithSendInvitation($campaign, $siteId, false);

        $created = [];
        try {
            $response = $this->withSession(fn() => $this->recipientsController()->actionAdd());
            self::assertInstanceOf(\yii\web\Response::class, $response);
            self::assertSame(302, $response->getStatusCode(), 'The form submission is answered with a redirect.');
        } finally {
            $created = $this->ownRecipientsWithEmail($email);
        }
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * Install the Add Recipient request and return the email it adds.
     */
    private function requestAddRecipientWithSendInvitation(Campaign $campaign, int $siteId, bool $acceptsJson): string
    {
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteId,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sendInvitation' => '1',
        ], $acceptsJson);

        return $email;
    }

    /**
     * @return list<int>
     */
    private function ownRecipientsWithEmail(string $email): array
    {
        $created = [];
        foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
            $created[] = (int)$recipientId;
            $this->ownRecipient((int)$recipientId);
        }

        return $created;
    }

    /**
     * Run the import step for the given number of valid email rows with
     * sending on.
     *
     * @return list<int> The recipients the import created, in creation order
     */
    private function importRows(Campaign $campaign, int $siteId, int $rowCount): array
    {
        $campaignId = (int)$campaign->id;
        $validRows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $validRows[] = [
                'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
                'email' => 'imported-' . bin2hex(random_bytes(4)) . '@example.test',
                'sms' => null,
                'siteId' => $siteId,
                'siteName' => Craft::$app->getSites()->getSiteById($siteId)?->name ?? '',
            ];
        }

        $this->session()->set('recipient-import-preview', [
            'validRows' => $validRows,
            'campaignId' => $campaignId,
            'siteId' => $siteId,
            'queueSending' => true,
            'summary' => ['totalRows' => $rowCount, 'validRows' => $rowCount, 'duplicates' => 0, 'errors' => 0],
            'duplicateRows' => [],
            'errorRows' => [],
            'filename' => 'recipients.csv',
        ]);
        $this->request('POST', ['site' => $this->siteHandle($siteId)], ['campaignId' => (string)$campaignId]);

        $before = $this->campaignRecipientIds($campaignId);
        $created = [];
        try {
            $this->withSession(fn() => $this->recipientsController()->actionImport());
        } finally {
            $created = array_values(array_diff($this->campaignRecipientIds($campaignId), $before));
            foreach ($created as $recipientId) {
                $this->ownRecipient($recipientId);
            }
        }

        return $created;
    }

    /**
     * Run the campaign for the site as a worker would.
     */
    private function runCampaign(Campaign $campaign, int $siteId): void
    {
        (new ProcessCampaignJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteId,
            'sendSms' => true,
            'sendEmail' => true,
        ]))->execute(Craft::$app->getQueue());
    }

    /**
     * @return list<int>
     */
    private function campaignRecipientIds(int $campaignId): array
    {
        return array_map('intval', RecipientRecord::find()
            ->select('id')
            ->where(['campaignId' => $campaignId])
            ->orderBy('id')
            ->column());
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
