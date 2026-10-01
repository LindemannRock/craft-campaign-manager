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
use craft\errors\MissingComponentException;
use InvalidArgumentException;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\CampaignsController;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\migrations\Install;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\ActivityLogSiteRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\ActivityLogsService;
use lindemannrock\campaignmanager\tests\Stubs\SiteRowsFailingActivityLogsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\db\Expression;

/**
 * Every activity log records the complete set of sites it covers, written
 * together with the log, and the schema carries that record.
 *
 * @since 5.16.0
 */
#[CoversClass(ActivityLogsService::class)]
#[CoversClass(ActivityLogRecord::class)]
#[CoversClass(ActivityLogSiteRecord::class)]
#[CoversClass(Install::class)]
#[CoversClass(CampaignsController::class)]
#[CoversClass(RecipientsController::class)]
#[CoversClass(ProcessCampaignJob::class)]
#[CoversClass(SendBatchJob::class)]
final class ActivityLogSiteScopeRecordingTest extends SiteRestrictedUserTestCase
{
    private const CAMPAIGN_PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:createCampaigns',
        'campaignManager:editCampaigns',
        'campaignManager:deleteCampaigns',
        'campaignManager:runCampaigns',
        'campaignManager:manageRecipients',
    ];

    private const RECIPIENT_PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
        'campaignManager:deleteRecipients',
        'campaignManager:exportRecipients',
    ];

    public function testALogIsWrittenTogetherWithTheSitesItCovers(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $this->actAsUserRestrictedTo([$siteA, $siteB], []);

        $sites = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteB, $siteA, $siteA]);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $sites->siteScope);
        self::assertSame([$siteA, $siteB], $this->logSiteIds((int)$sites->id));

        $all = $this->writeOwnedLog(ActivityLogRecord::SCOPE_ALL, []);
        self::assertSame(ActivityLogRecord::SCOPE_ALL, $all->siteScope);
        self::assertSame([], $this->logSiteIds((int)$all->id));

        $unknown = $this->writeOwnedLog(ActivityLogRecord::SCOPE_UNKNOWN, []);
        self::assertSame(ActivityLogRecord::SCOPE_UNKNOWN, $unknown->siteScope);
        self::assertSame([], $this->logSiteIds((int)$unknown->id));

        CampaignManager::$plugin->activityLogs->log('campaign_updated', ['summary' => 'no scope given']);
        $withoutScope = $this->latestOwnedLog('campaign_updated');
        self::assertNotNull($withoutScope);
        self::assertSame(ActivityLogRecord::SCOPE_UNKNOWN, $withoutScope->siteScope);
    }

    public function testScopesThatDoNotFitTheirSitesAreRefused(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsUserRestrictedTo([$siteA], []);
        $service = CampaignManager::$plugin->activityLogs;

        foreach ([
            'sites without a site' => [ActivityLogRecord::SCOPE_SITES, []],
            'all with a site' => [ActivityLogRecord::SCOPE_ALL, [$siteA]],
            'unknown with a site' => [ActivityLogRecord::SCOPE_UNKNOWN, [$siteA]],
            'a scope that does not exist' => ['everywhere', []],
            'a site that does not exist' => [ActivityLogRecord::SCOPE_SITES, [$siteA, 987654321]],
            'a site ID that is not a number' => [ActivityLogRecord::SCOPE_SITES, ['primary']],
        ] as $case => [$siteScope, $siteIds]) {
            try {
                $service->log('recipient_added', ['siteScope' => $siteScope, 'siteIds' => $siteIds]);
                self::fail("Expected {$case} to be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testALogWhoseSiteRowsCannotBeWrittenIsNotKept(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsUserRestrictedTo([$siteA], []);
        $this->swapPluginComponent('campaign-manager', 'activityLogs', new SiteRowsFailingActivityLogsService());

        try {
            CampaignManager::$plugin->activityLogs->log('recipient_added', [
                'siteScope' => ActivityLogRecord::SCOPE_SITES,
                'siteIds' => [$siteA],
            ]);
            self::fail('Expected the failed site rows to fail the log.');
        } catch (\RuntimeException $e) {
            self::assertSame('The site rows could not be written.', $e->getMessage());
        }

        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testDeletingALogRemovesItsSiteRows(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $this->actAsUserRestrictedTo([$siteA, $siteB], []);
        $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB]);
        self::assertCount(2, $this->logSiteIds((int)$log->id));

        // Trimming and clearing delete logs this way and rely on the same cascade
        ActivityLogRecord::deleteAll(['id' => $log->id]);

        self::assertNull(ActivityLogRecord::findOne($log->id));
        self::assertSame([], $this->logSiteIds((int)$log->id));
    }

    public function testALogStoredWithoutASiteScopeReadsAsUnknown(): void
    {
        [$siteA] = $this->siteIds(1);
        $user = $this->actAsUserRestrictedTo([$siteA], []);

        // A row as the plugin wrote it before site scope existed
        $legacy = new ActivityLogRecord([
            'userId' => (int)$user->id,
            'action' => 'recipient_added',
            'source' => 'manual',
            'summary' => 'written before site scope existed',
        ]);
        self::assertTrue($legacy->save(false));

        $stored = ActivityLogRecord::findOne($legacy->id);
        self::assertNotNull($stored);
        self::assertSame(ActivityLogRecord::SCOPE_UNKNOWN, $stored->siteScope);
        self::assertSame([], $this->logSiteIds((int)$legacy->id));
    }

    public function testTheSchemaRecordsSiteScopeAndSiteRows(): void
    {
        $db = Craft::$app->getDb();
        $schema = $db->getSchema();
        $logTable = $db->getSchema()->getRawTableName(ActivityLogRecord::tableName());
        $siteTable = $db->getSchema()->getRawTableName(ActivityLogSiteRecord::tableName());
        $sitesTable = $db->getSchema()->getRawTableName('{{%sites}}');

        $logSchema = $schema->getTableSchema(ActivityLogRecord::tableName(), true);
        self::assertNotNull($logSchema);
        $siteScope = $logSchema->getColumn('siteScope');
        self::assertNotNull($siteScope);
        self::assertSame('string', $siteScope->type);
        self::assertSame(10, $siteScope->size);
        self::assertFalse($siteScope->allowNull);
        self::assertSame(ActivityLogRecord::SCOPE_UNKNOWN, $siteScope->defaultValue);
        self::assertContains(['siteScope'], array_column($schema->findIndexes(ActivityLogRecord::tableName()), 'columns'));

        $siteSchema = $schema->getTableSchema(ActivityLogSiteRecord::tableName(), true);
        self::assertNotNull($siteSchema);
        self::assertSame(['id', 'logId', 'siteId', 'dateCreated', 'dateUpdated', 'uid'], $siteSchema->columnNames);
        self::assertSame(['id'], $siteSchema->primaryKey);
        self::assertFalse($siteSchema->getColumn('logId')?->allowNull);
        // A deleted site leaves a null row behind instead of taking the row with it
        self::assertTrue($siteSchema->getColumn('siteId')?->allowNull);
        self::assertContains(['logId', 'siteId'], array_values($schema->findUniqueIndexes($siteSchema)));
        self::assertContains(['siteId'], array_column($schema->findIndexes(ActivityLogSiteRecord::tableName()), 'columns'));

        $foreignKeys = array_map(static function(array $foreignKey): array {
            $table = array_shift($foreignKey);

            return [$table, $foreignKey];
        }, array_values($siteSchema->foreignKeys));
        self::assertContains([$logTable, ['logId' => 'id']], $foreignKeys);
        self::assertContains([$sitesTable, ['siteId' => 'id']], $foreignKeys);
        self::assertNotSame($logTable, $siteTable);

        // Deleting a log takes its site rows along; deleting a site keeps the row as null
        self::assertSame(['logId' => 'CASCADE', 'siteId' => 'SET NULL'], $this->foreignKeyDeleteRules(ActivityLogSiteRecord::tableName()));
    }

    /**
     * The delete rule of each foreign key of a table, by column, read from the
     * standard information schema so MySQL and PostgreSQL answer the same way.
     *
     * @return array<string, string>
     */
    private function foreignKeyDeleteRules(string $table): array
    {
        $db = Craft::$app->getDb();
        $rows = (new Query())
            ->select(['fkColumn' => 'kcu.column_name', 'deleteRule' => 'rc.delete_rule'])
            ->from(['rc' => 'information_schema.referential_constraints'])
            ->innerJoin(
                ['kcu' => 'information_schema.key_column_usage'],
                '[[kcu.constraint_name]] = [[rc.constraint_name]] AND [[kcu.constraint_schema]] = [[rc.constraint_schema]]',
            )
            ->where(['kcu.table_name' => $db->getSchema()->getRawTableName($table)])
            ->andWhere(new Expression('[[rc.constraint_schema]] = ' . ($db->getIsMysql() ? 'DATABASE()' : 'current_schema()')))
            ->all();

        $rules = [];
        foreach ($rows as $row) {
            $rules[(string)$row['fkColumn']] = (string)$row['deleteRule'];
        }
        ksort($rules);

        return $rules;
    }

    public function testSavingAndDeletingACampaignCoverEverySite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::CAMPAIGN_PERMISSIONS);

        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteA,
            'title' => 'Saved on one site',
            'enabled' => '1',
            'formId' => (string)$campaign->formId,
        ], false);
        $this->reachSession(fn() => $this->campaignsController()->actionSave());
        $this->assertLogScope('campaign_updated', ActivityLogRecord::SCOPE_ALL, []);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => (string)$siteA], false);
        $this->reachSession(fn() => $this->campaignsController()->actionDelete());
        $this->assertLogScope('campaign_deleted', ActivityLogRecord::SCOPE_ALL, []);
    }

    public function testCreatingACampaignCoversEverySite(): void
    {
        [$siteA] = $this->siteIds(2);
        $form = $this->seedForm(['comment']);
        $title = $this->nextTestMarker('Campaign Manager test ', 'campaign');
        $this->actAsUserRestrictedTo([$siteA], self::CAMPAIGN_PERMISSIONS);

        $this->request('POST', [], [
            'siteId' => (string)$siteA,
            'title' => $title,
            'enabled' => '1',
            'formId' => (string)$form->id,
        ], false);
        try {
            $this->reachSession(fn() => $this->campaignsController()->actionSave());
        } finally {
            foreach (Campaign::find()->title($title)->status(null)->siteId('*')->unique()->ids() as $campaignId) {
                $this->ownCampaign((int)$campaignId);
            }
        }

        $this->assertLogScope('campaign_created', ActivityLogRecord::SCOPE_ALL, []);
    }

    public function testRunningCampaignsCoversEverySiteAJobWasQueuedFor(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::CAMPAIGN_PERMISSIONS);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id]);
        self::assertSame(2, $this->campaignsController()->actionRunAll()->data['jobsQueued']);
        $this->assertLogScope('campaigns_queued', ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB]);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => (string)$siteB]);
        self::assertSame(1, $this->campaignsController()->actionRunAll()->data['jobsQueued']);
        $this->assertLogScope('campaigns_queued', ActivityLogRecord::SCOPE_SITES, [$siteB]);
        self::assertNotContains($siteC, $this->logSiteIds((int)$this->latestOwnedLog('campaigns_queued')->id));
    }

    public function testQueueJobsCoverTheirSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $campaign->emailInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $user = $this->actAsUserRestrictedTo([$siteA], self::CAMPAIGN_PERMISSIONS);

        // Processing only queues batches; nothing is sent here
        (new ProcessCampaignJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteA,
            'sendSms' => false,
            'sendEmail' => true,
            'triggeredByUserId' => (int)$user->id,
        ]))->execute(Craft::$app->getQueue());
        $this->assertLogScope('campaign_batches_queued', ActivityLogRecord::SCOPE_SITES, [$siteA]);
        self::assertGreaterThan(0, $this->ownedQueueJobCount());

        // Nothing is sent: both channels are off, so only the batch log is written
        (new SendBatchJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteA,
            'recipientIds' => [(int)$recipient->id],
            'sendSms' => false,
            'sendEmail' => false,
            'triggeredByUserId' => (int)$user->id,
        ]))->execute(Craft::$app->getQueue());
        $this->assertLogScope('invitations_sent_batch', ActivityLogRecord::SCOPE_SITES, [$siteA]);
        self::assertNull($this->reloadRecipient($recipient)->emailSendDate);
    }

    public function testRecipientChangesCoverTheRecipientSite(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::RECIPIENT_PERMISSIONS);
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';

        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteB,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
        ]);
        try {
            self::assertTrue($this->recipientsController()->actionAdd()->data['success']);
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $this->ownRecipient((int)$recipientId);
            }
        }
        $this->assertLogScope('recipient_added', ActivityLogRecord::SCOPE_SITES, [$siteB]);

        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $this->request('POST', [], ['id' => (string)$recipient->id]);
        self::assertTrue($this->recipientsController()->actionDeleteFromCp()->data['success']);
        self::assertNull(RecipientRecord::findOne($recipient->id));
    }

    public function testBulkDeletionCoversEverySiteEvenBeyondTheShownRecipients(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $recipientIds = [];
        for ($i = 0; $i < 10; $i++) {
            $recipientIds[] = (string)$this->seedOwnedRecipient($campaign, $siteA)->id;
        }
        for ($i = 0; $i < 2; $i++) {
            $recipientIds[] = (string)$this->seedOwnedRecipient($campaign, $siteB)->id;
        }
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::RECIPIENT_PERMISSIONS);

        $this->request('POST', [], ['recipientIds' => $recipientIds]);
        self::assertSame(12, $this->recipientsController()->actionBulkDelete()->data['count']);

        $log = $this->assertLogScope('recipients_deleted', ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB]);
        $details = json_decode((string)$log->details, true);
        self::assertSame(12, $details['count']);
        self::assertCount(10, $details['recipients']);
        self::assertSame([$siteA], array_values(array_unique(array_column($details['recipients'], 'siteId'))));
    }

    public function testExportsCoverTheSitesThatWereExported(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA);
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->seedOwnedRecipient($campaign, $siteC);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::RECIPIENT_PERMISSIONS);

        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaign->id, 'dateRange' => 'all']);
        $this->recipientsController()->actionExport();
        $this->assertLogScope('recipients_exported', ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB]);

        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteFilter' => (string)$siteA]);
        $this->recipientsController()->actionExport();
        $this->assertLogScope('recipients_exported', ActivityLogRecord::SCOPE_SITES, [$siteA]);

        $this->request('POST', [], ['format' => 'csv', 'campaignId' => (string)$campaign->id, 'dateRange' => 'all', 'site' => $this->siteHandle($siteB)]);
        $this->recipientsController()->actionExportRecipients();
        $this->assertLogScope('campaign_recipients_exported', ActivityLogRecord::SCOPE_SITES, [$siteB]);
    }

    /**
     * Assert the newest owned log of the action has the scope and sites.
     *
     * @param list<int> $siteIds
     */
    private function assertLogScope(string $action, string $siteScope, array $siteIds): ActivityLogRecord
    {
        $log = $this->latestOwnedLog($action);
        self::assertNotNull($log, "No {$action} log was written.");
        self::assertSame($siteScope, $log->siteScope);
        sort($siteIds);
        self::assertSame($siteIds, $this->logSiteIds((int)$log->id));

        return $log;
    }

    /**
     * Run an action whose success is reported through the session, which the
     * test runtime does not have. Reaching it is the outcome.
     */
    private function reachSession(callable $action): void
    {
        try {
            $action();
        } catch (MissingComponentException $e) {
            self::assertStringContainsString('Session', $e->getMessage());

            return;
        }

        self::fail('Expected the action to reach the session.');
    }
}
