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
use lindemannrock\base\helpers\CsvImportHelper;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\FailingActivityLogsService;
use lindemannrock\campaignmanager\tests\Stubs\InMemorySession;
use lindemannrock\campaignmanager\tests\Stubs\OwnedCsvUpload;
use lindemannrock\campaignmanager\tests\Stubs\RecordingEmailsService;
use lindemannrock\campaignmanager\tests\Stubs\RecordingSmsInvitationService;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * "Send Invitations" on a recipient import queues invitations for exactly the
 * recipients that import created, and for nobody else.
 *
 * The import's session data is held by an in-memory session that the
 * console application hands out only while a controller action runs. Queued
 * jobs are read back from the queue table exactly as a worker receives them.
 *
 * @since 5.16.0
 */
#[CoversClass(RecipientsController::class)]
final class RecipientImportInvitationQueueingTest extends SiteRestrictedUserTestCase
{
    /**
     * The import permission and the permission it is nested under; nothing
     * that runs campaigns.
     */
    private const IMPORT_PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:importRecipients',
    ];

    private ?InMemorySession $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new InMemorySession();
    }

    protected function cleanupExternalState(): void
    {
        $this->session = null;
        parent::cleanupExternalState();
    }

    public function testOnlyTheRecipientsCreatedByTheImportAreQueued(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $olderOnSameSite = $this->seedOwnedRecipient($campaign, $siteA);
        $olderOnOtherSite = $this->seedOwnedRecipient($campaign, $siteB);
        $olderRowsBefore = [$this->persistedRecipientRow((int)$olderOnSameSite->id), $this->persistedRecipientRow((int)$olderOnOtherSite->id)];
        $user = $this->actAsUserRestrictedTo([$siteA, $siteB], self::IMPORT_PERMISSIONS);
        self::assertFalse($user->can('campaignManager:runCampaigns'));

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteA),
            $this->row($siteA),
        ]);

        self::assertCount(2, $created);
        self::assertEquals([$siteA => $created], $this->queuedRecipientIdsBySite((int)$campaign->id));
        self::assertSame($olderRowsBefore, [$this->persistedRecipientRow((int)$olderOnSameSite->id), $this->persistedRecipientRow((int)$olderOnOtherSite->id)]);
        $this->assertLogScopes($campaign, [$siteA], [$siteA]);
    }

    public function testImportedRecipientsAreQueuedPerSite(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA);
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->seedOwnedRecipient($campaign, $siteC);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteB),
            $this->row($siteA),
            $this->row($siteB),
        ]);

        self::assertCount(3, $created);
        self::assertEquals([
            $siteA => [$created[1]],
            $siteB => [$created[0], $created[2]],
        ], $this->queuedRecipientIdsBySite((int)$campaign->id));
        $this->assertLogScopes($campaign, [$siteA, $siteB], [$siteA, $siteB]);
    }

    public function testRowsForASiteTheUserCannotEditAreNeitherImportedNorQueued(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteB),
            $this->row($siteA),
        ]);

        self::assertCount(1, $created);
        self::assertSame($siteA, $this->siteOfRecipient($created[0]));
        self::assertEquals([$siteA => $created], $this->queuedRecipientIdsBySite((int)$campaign->id));
        $this->assertLogScopes($campaign, [$siteA], [$siteA]);
    }

    public function testRowsForASiteWhereTheCampaignIsDisabledAreNeitherImportedNorQueued(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->disableOwnedCampaignForSite($campaign, $siteB);
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteA),
            $this->row($siteB),
        ]);

        self::assertCount(1, $created);
        self::assertSame($siteA, $this->siteOfRecipient($created[0]));
        self::assertEquals([$siteA => $created], $this->queuedRecipientIdsBySite((int)$campaign->id));
        $this->assertLogScopes($campaign, [$siteA], [$siteA]);
    }

    public function testARowThatCannotBeSavedIsNotQueued(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        // A name longer than the column allows passes the preview but fails to save
        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteA, ['name' => str_repeat('n', 256)]),
            $this->row($siteA),
        ]);

        self::assertCount(1, $created);
        self::assertEquals([$siteA => $created], $this->queuedRecipientIdsBySite((int)$campaign->id));
        $this->assertLogScopes($campaign, [$siteA], [$siteA]);
    }

    public function testARowMatchingAnExistingRecipientQueuesOnlyTheRecipientItCreates(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $existing = $this->seedOwnedRecipient($campaign, $siteA);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteA, ['email' => $existing->email]),
        ]);

        self::assertCount(1, $created);
        self::assertNotSame((int)$existing->id, $created[0]);
        self::assertEquals([$siteA => $created], $this->queuedRecipientIdsBySite((int)$campaign->id));
    }

    public function testNothingIsQueuedWhenSendingIsSwitchedOff(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [$this->row($siteA)], false);

        self::assertCount(1, $created);
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
        self::assertNull($this->latestOwnedLog('campaign_invitations_queued'));
        self::assertSame([$siteA], $this->logSiteIds((int)$this->latestOwnedLog('recipients_imported')->id));
    }

    public function testNothingIsQueuedWhenNoRowWasImported(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);

        $created = $this->importRows($campaign, $siteA, [$this->row($siteB)]);

        self::assertSame([], $created);
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
        self::assertNull($this->latestOwnedLog('campaign_invitations_queued'));
        self::assertSame([$siteA], $this->logSiteIds((int)$this->latestOwnedLog('recipients_imported')->id));
    }

    public function testQueuedBatchesFollowTheWorkerBatchSize(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);
        $rows = [];
        for ($i = 0; $i <= ProcessCampaignJob::BATCH_SIZE; $i++) {
            $rows[] = $this->row($siteA);
        }

        $created = $this->importRows($campaign, $siteA, $rows);

        $batches = $this->queuedSendBatches((int)$campaign->id);
        self::assertCount(2, $batches);
        self::assertSame(array_slice($created, 0, ProcessCampaignJob::BATCH_SIZE), $batches[0]->recipientIds);
        self::assertSame(array_slice($created, ProcessCampaignJob::BATCH_SIZE), $batches[1]->recipientIds);
        self::assertSame([$siteA, $siteA], [$batches[0]->siteId, $batches[1]->siteId]);
    }

    public function testTheQueuedBatchSendsToEachImportedRecipientOnItsChannels(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $campaign->emailInvitationMessage = 'Please take part.';
        $campaign->smsInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);
        $older = $this->seedOwnedRecipient($campaign, $siteA, ['sms' => '+96597176606']);
        $user = $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);
        $emails = new RecordingEmailsService();
        $sms = new RecordingSmsInvitationService();
        $this->swapPluginComponent('campaign-manager', 'emails', $emails);
        $this->swapPluginComponent('campaign-manager', 'recipients', $sms);

        [$emailOnly, $smsOnly, $both] = $this->importRows($campaign, $siteA, [
            $this->row($siteA),
            $this->row($siteA, ['email' => null, 'sms' => '+96597176606']),
            $this->row($siteA, ['sms' => '+96597176607']),
        ]);

        $batches = $this->queuedSendBatches((int)$campaign->id);
        self::assertCount(1, $batches);
        $batch = $batches[0];
        self::assertSame((int)$campaign->id, $batch->campaignId);
        self::assertSame($siteA, $batch->siteId);
        self::assertTrue($batch->sendSms);
        self::assertTrue($batch->sendEmail);
        self::assertSame((int)$user->id, $batch->triggeredByUserId);
        // An import batch only sends while the campaign is still enabled for its site
        self::assertTrue($batch->onlyWhileCampaignEnabled);

        $batch->execute(Craft::$app->getQueue());

        self::assertSame([$emailOnly, $both], $emails->sentToRecipientIds);
        self::assertSame([$smsOnly, $both], $sms->sentToRecipientIds);
        self::assertNotContains((int)$older->id, array_merge($emails->sentToRecipientIds, $sms->sentToRecipientIds));
        self::assertSame([$siteA], $this->logSiteIds((int)$this->latestOwnedLog('invitations_sent_batch')->id));
    }

    public function testAnImportBatchSendsNothingWhenTheCampaignIsDisabledForItsSiteBeforeItRuns(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->assertNothingIsSentOnceTheCampaignIsDisabledForTheSite($campaign, $siteA);
    }

    public function testAFailedImportLogStopsTheImportBeforeAnythingIsQueued(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);
        $logs = new FailingActivityLogsService();
        $this->swapPluginComponent('campaign-manager', 'activityLogs', $logs);

        try {
            $this->importRows($campaign, $siteA, [$this->row($siteA)]);
            self::fail('The failed log was expected to surface.');
        } catch (RuntimeException $e) {
            self::assertSame('The activity log could not be written.', $e->getMessage());
        }

        self::assertCount(1, $this->campaignRecipientIds((int)$campaign->id));
        self::assertSame(['recipients_imported'], $logs->attemptedActions);
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
    }

    public function testACsvImportQueuesOnlyTheRecipientsItCreated(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA);
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::IMPORT_PERMISSIONS);
        $campaignId = (int)$campaign->id;
        $rowA = $this->row($siteA);
        $rowB = $this->row($siteB);

        // Upload: the file is parsed exactly as the upload step parses it
        $parsed = $this->parseOwnedCsv("Name,Email,Site\n"
            . "{$rowA['name']},{$rowA['email']},{$this->siteHandle($siteA)}\n"
            . "{$rowB['name']},{$rowB['email']},{$this->siteHandle($siteB)}\n");
        $this->session()->set('recipient-import', [
            'headers' => $parsed['headers'],
            'allRows' => $parsed['allRows'],
            'rowCount' => $parsed['rowCount'],
            'campaignId' => $campaignId,
            'queueSending' => true,
            'filename' => 'recipients.csv',
        ]);

        // Map
        $this->request('GET', ['site' => $this->siteHandle($siteA)]);
        $map = $this->withSession(fn() => $this->recipientsController()->actionMap($campaignId));
        self::assertSame(['Name', 'Email', 'Site'], $map->data['variables']['headers']);

        // Preview
        $this->request('POST', ['site' => $this->siteHandle($siteA)], [
            'campaignId' => (string)$campaignId,
            'queueSending' => '1',
            'mapping' => ['0' => 'name', '1' => 'email', '2' => 'site'],
            'defaultSiteId' => (string)$siteA,
            'phoneCountry' => '',
        ]);
        $preview = $this->withSession(fn() => $this->recipientsController()->actionPreview());
        self::assertSame(2, $preview->data['variables']['summary']['validRows']);
        self::assertTrue($preview->data['variables']['queueSending']);

        // Import
        $before = $this->campaignRecipientIds($campaignId);
        $this->request('POST', ['site' => $this->siteHandle($siteA)], ['campaignId' => (string)$campaignId]);
        $created = [];
        try {
            $this->withSession(fn() => $this->recipientsController()->actionImport());
        } finally {
            $created = $this->ownCreatedRecipients($campaignId, $before);
        }

        self::assertCount(2, $created);
        self::assertEquals([
            $siteA => [$created[0]],
            $siteB => [$created[1]],
        ], $this->queuedRecipientIdsBySite($campaignId));
        self::assertStringContainsString('Invitation sending has been queued.', (string)$this->session()->getNotice());
        self::assertFalse($this->session()->has('recipient-import'));
        self::assertFalse($this->session()->has('recipient-import-preview'));
        $this->assertLogScopes($campaign, [$siteA, $siteB], [$siteA, $siteB]);
    }

    /**
     * Import an email and an SMS recipient with sending on, disable the
     * campaign for that site, then run the queued batch as a worker would.
     */
    private function assertNothingIsSentOnceTheCampaignIsDisabledForTheSite(Campaign $campaign, int $siteA): void
    {
        $campaign->emailInvitationMessage = 'Please take part.';
        $campaign->smsInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);
        $this->actAsUserRestrictedTo([$siteA], self::IMPORT_PERMISSIONS);
        $emails = new RecordingEmailsService();
        $sms = new RecordingSmsInvitationService();
        $this->swapPluginComponent('campaign-manager', 'emails', $emails);
        $this->swapPluginComponent('campaign-manager', 'recipients', $sms);

        $created = $this->importRows($campaign, $siteA, [
            $this->row($siteA),
            $this->row($siteA, ['sms' => '+96597176606']),
        ]);
        $batches = $this->queuedSendBatches((int)$campaign->id);
        self::assertCount(1, $batches);
        $rowsBefore = array_map($this->persistedRecipientRow(...), $created);
        $logsBefore = $this->ownedActivityLogCount();

        $this->disableOwnedCampaignForSite($campaign, $siteA);
        $batches[0]->execute(Craft::$app->getQueue());

        self::assertSame([], $emails->sentToRecipientIds, 'No email may be sent once the campaign is disabled.');
        self::assertSame([], $sms->sentToRecipientIds, 'No SMS may be sent once the campaign is disabled.');
        self::assertSame($rowsBefore, array_map($this->persistedRecipientRow(...), $created));
        self::assertNull($this->latestOwnedLog('invitations_sent_batch'));
        self::assertSame($logsBefore, $this->ownedActivityLogCount());
        self::assertTrue($batches[0]->onlyWhileCampaignEnabled);
    }

    /**
     * A valid preview row for the given site.
     *
     * @param array<string, mixed> $overrides
     * @return array{name: string, email: string|null, sms: string|null, siteId: int}
     */
    private function row(int $siteId, array $overrides = []): array
    {
        return array_merge([
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => 'imported-' . bin2hex(random_bytes(4)) . '@example.test',
            'sms' => null,
            'siteId' => $siteId,
        ], $overrides);
    }

    /**
     * Run the import step with the given rows already validated by the preview.
     *
     * @param list<array{name: string, email: string|null, sms: string|null, siteId: int}> $rows
     * @return list<int> The recipients the import created, in creation order
     */
    private function importRows(Campaign $campaign, int $startedOnSiteId, array $rows, bool $queueSending = true): array
    {
        $campaignId = (int)$campaign->id;
        $validRows = [];
        foreach ($rows as $row) {
            $validRows[] = [
                'name' => $row['name'],
                'email' => $row['email'],
                'sms' => $row['sms'],
                'siteId' => $row['siteId'],
                'siteName' => Craft::$app->getSites()->getSiteById($row['siteId'])?->name ?? '',
            ];
        }

        $this->session()->set('recipient-import-preview', [
            'validRows' => $validRows,
            'campaignId' => $campaignId,
            'siteId' => $startedOnSiteId,
            'queueSending' => $queueSending,
            'summary' => ['totalRows' => count($rows), 'validRows' => count($rows), 'duplicates' => 0, 'errors' => 0],
            'duplicateRows' => [],
            'errorRows' => [],
            'filename' => 'recipients.csv',
        ]);
        $this->request('POST', ['site' => $this->siteHandle($startedOnSiteId)], ['campaignId' => (string)$campaignId]);

        $before = $this->campaignRecipientIds($campaignId);
        $created = [];
        try {
            $this->withSession(fn() => $this->recipientsController()->actionImport());
        } finally {
            $created = $this->ownCreatedRecipients($campaignId, $before);
        }

        return $created;
    }

    /**
     * Take ownership of the recipients an action created for the campaign.
     *
     * @param list<int> $before
     * @return list<int>
     */
    private function ownCreatedRecipients(int $campaignId, array $before): array
    {
        $created = array_values(array_diff($this->campaignRecipientIds($campaignId), $before));
        foreach ($created as $recipientId) {
            $this->ownRecipient($recipientId);
        }

        return $created;
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

    private function siteOfRecipient(int $recipientId): int
    {
        return (int)RecipientRecord::find()->select('siteId')->where(['id' => $recipientId])->scalar();
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedRecipientRow(int $recipientId): array
    {
        $row = (new Query())->from(RecipientRecord::tableName())->where(['id' => $recipientId])->one();
        if ($row === false) {
            throw new RuntimeException("Recipient {$recipientId} does not exist.");
        }

        return $row;
    }

    /**
     * Parse a CSV the way the upload step does, from a file the test owns.
     *
     * @return array{headers: list<string>, allRows: list<list<string>>, rowCount: int}
     */
    private function parseOwnedCsv(string $contents): array
    {
        $path = $this->createTrackedTempDirectory('cm_import_') . '/recipients.csv';
        file_put_contents($path, $contents);

        return CsvImportHelper::parseUpload(OwnedCsvUpload::fromPath($path), [
            'maxRows' => CsvImportHelper::DEFAULT_MAX_ROWS,
            'maxBytes' => CsvImportHelper::DEFAULT_MAX_BYTES,
            'delimiter' => null,
            'detectDelimiter' => true,
        ]);
    }

    private function session(): InMemorySession
    {
        if ($this->session === null) {
            throw new RuntimeException('No import session is installed.');
        }

        return $this->session;
    }

    /**
     * Run a controller action with the in-memory session available as the
     * application session, then put the real application back.
     */
    private function withSession(callable $action): mixed
    {
        $application = Craft::$app;
        if (!$application instanceof \craft\console\Application) {
            throw new RuntimeException('The test runtime is expected to be a console application.');
        }
        $proxy = new SessionApplication($application, $this->session());
        Craft::$app = $proxy;
        \Yii::$app = $proxy;

        try {
            return $action();
        } finally {
            Craft::$app = $application;
            \Yii::$app = $application;
        }
    }

    /**
     * The send batches queued for the campaign; any other job kind fails the test.
     *
     * @return list<SendBatchJob>
     */
    private function queuedSendBatches(int $campaignId): array
    {
        $batches = [];
        foreach ($this->queuedJobs($campaignId) as $job) {
            self::assertInstanceOf(SendBatchJob::class, $job, 'Only exact send batches may be queued by an import.');
            $batches[] = $job;
        }

        return $batches;
    }

    /**
     * The recipient IDs queued for the campaign, grouped by site.
     *
     * @return array<int, list<int>>
     */
    private function queuedRecipientIdsBySite(int $campaignId): array
    {
        $bySite = [];
        foreach ($this->queuedSendBatches($campaignId) as $batch) {
            self::assertSame($campaignId, $batch->campaignId);
            foreach ($batch->recipientIds as $recipientId) {
                $bySite[$batch->siteId][] = $recipientId;
            }
        }
        ksort($bySite);

        return $bySite;
    }

    /**
     * Assert the sites the import log and the queued-invitations log cover.
     *
     * @param list<int> $importedSiteIds
     * @param list<int> $queuedSiteIds
     */
    private function assertLogScopes(Campaign $campaign, array $importedSiteIds, array $queuedSiteIds): void
    {
        sort($importedSiteIds);
        sort($queuedSiteIds);
        $imported = $this->latestOwnedLog('recipients_imported');
        self::assertNotNull($imported);
        self::assertSame((int)$campaign->id, (int)$imported->campaignId);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $imported->siteScope);
        self::assertSame($importedSiteIds, $this->logSiteIds((int)$imported->id));

        $queued = $this->latestOwnedLog('campaign_invitations_queued');
        self::assertNotNull($queued);
        self::assertSame((int)$campaign->id, (int)$queued->campaignId);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $queued->siteScope);
        self::assertSame($queuedSiteIds, $this->logSiteIds((int)$queued->id));
    }
}
