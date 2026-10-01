<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\queue\Queue;
use craft\records\Site as SiteRecord;
use craft\services\UserPermissions;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\ActivityLogSiteRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\CapturingActivityLogsController;
use lindemannrock\campaignmanager\tests\Stubs\CapturingCampaignsController;
use lindemannrock\campaignmanager\tests\Stubs\CapturingRecipientsController;
use lindemannrock\campaignmanager\tests\Stubs\NonTrimmingActivityLogsService;
use lindemannrock\campaignmanager\tests\TestCase;
use RuntimeException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Base for tests that run control panel actions as a user who may edit only
 * some of the project's sites.
 *
 * The project's existing sites are used as they are. Every user, permission,
 * campaign, recipient, queue job and activity log created here is recorded by
 * its exact identity and removed during teardown; the request, response, user
 * and view state that were active before the test are put back.
 *
 * Saving and deleting the test users would otherwise hand Search Manager
 * pending-sync rows and a queue job that outlive the test, so its automatic
 * indexing is switched off in memory for the duration of each test and put
 * back exactly afterwards.
 *
 * @since 5.16.0
 */
abstract class SiteRestrictedUserTestCase extends TestCase
{
    protected const USER_MARKER = 'cm_site_access_';

    private const SEARCH_MANAGER_PENDING_SYNCS = '{{%searchmanager_pending_syncs}}';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?object $originalUser = null;
    private ?string $originalRequestMethod = null;
    private bool $hadRequestMethod = false;
    private ?string $originalTemplateMode = null;
    private ?object $searchManagerSettings = null;
    private ?bool $originalSearchManagerAutoIndex = null;

    /**
     * @var list<int>
     */
    private array $ownedUserIds = [];

    /**
     * @var list<int>
     */
    private array $ownedCampaignIds = [];

    /**
     * @var list<int>
     */
    private array $ownedRecipientIds = [];

    /**
     * @var list<int>
     */
    private array $ownedSiteIds = [];

    private ?object $originalUserPermissions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalUser = Craft::$app->getUser();
        $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
        $this->originalRequestMethod = $this->hadRequestMethod ? (string)$_SERVER['REQUEST_METHOD'] : null;
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $this->request('GET');
        $this->suspendSearchManagerAutoIndex();

        // Writing an activity log also trims old logs. Trimming is switched
        // off so a test run never removes logs it did not create.
        $this->swapPluginComponent('campaign-manager', 'activityLogs', new NonTrimmingActivityLogsService());
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            $this->cleanupOwnedQueueJobs(...),
            $this->cleanupOwnedActivityLogs(...),
            $this->cleanupOwnedWidgets(...),
            parent::cleanupExternalState(...),
            $this->restoreRequestState(...),
            $this->assertNoOwnedResidue(...),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreSearchManagerAutoIndex();
            // The identity was restored by now, so the editable sites are
            // recalculated for whoever runs next.
            Craft::$app->getSites()->refreshSites();
            $this->ownedUserIds = [];
            $this->ownedCampaignIds = [];
            $this->ownedRecipientIds = [];
            $this->ownedSiteIds = [];
        }
    }

    /**
     * Install a fresh control panel request.
     *
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $bodyParams
     * @param bool $acceptsJson Whether the request asks for a JSON answer, as the control panel's JavaScript does
     */
    protected function request(string $method, array $queryParams = [], array $bodyParams = [], bool $acceptsJson = true): void
    {
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setQueryParams($queryParams);
        $request->setBodyParams($bodyParams);
        $request->getHeaders()->set('Accept', $acceptsJson ? 'application/json' : 'text/html');

        $_SERVER['REQUEST_METHOD'] = $method;
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
    }

    /**
     * Act as a new user who holds the given plugin permissions and may edit
     * only the given sites.
     *
     * @param list<int> $siteIds
     * @param list<string> $permissions
     */
    protected function actAsUserRestrictedTo(array $siteIds, array $permissions): User
    {
        $user = $this->createTestUser(self::USER_MARKER);
        $this->ownedUserIds[] = (int)$user->id;
        $this->allowSites($user, $siteIds, $permissions);
        $this->actAs($user);

        return $user;
    }

    /**
     * Act as a user the test owns, for example to switch back to one that
     * was created earlier in the same test.
     */
    protected function actAs(User $user): void
    {
        if (!in_array((int)$user->id, $this->ownedUserIds, true)) {
            throw new RuntimeException('Only a user the test owns can act.');
        }

        $webUser = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?User
            {
                return null;
            }
        };
        $webUser->setIdentity($user);
        Craft::$app->set('user', $webUser);
        // The editable sites are cached per identity
        Craft::$app->getSites()->refreshSites();
        $this->syncTwigCurrentUser($user);
    }

    /**
     * Run a site lifecycle inside a transaction that is always rolled back.
     *
     * Craft creates and deletes sites through project config, which fires
     * events that other plugins and the queue react to across the whole
     * project. The activity log's site mapping only depends on the site row
     * itself, so the lifecycle writes and deletes that row exactly as Craft
     * does and rolls everything back afterwards. Users created inside the
     * lifecycle disappear with it; their cleanup finds nothing to do.
     */
    protected function withinRolledBackSiteLifecycle(callable $lifecycle): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $lifecycle();
        } finally {
            $transaction->rollBack();
            Craft::$app->getSites()->refreshSites();
            if ($this->originalUserPermissions !== null) {
                Craft::$app->set('userPermissions', $this->originalUserPermissions);
                $this->originalUserPermissions = null;
            }

            if ($this->ownedSiteIds !== [] && $this->countRows(Table::SITES, ['id' => $this->ownedSiteIds]) !== 0) {
                throw new RuntimeException('Owned site rows survived the rolled-back lifecycle.');
            }
            $this->ownedSiteIds = [];
        }
    }

    /**
     * Insert a site row the way Craft's site handler saves one, inside the
     * current rolled-back lifecycle, and make the sites service see it.
     */
    protected function insertOwnedSite(string $label): int
    {
        if (Craft::$app->getDb()->getTransaction() === null) {
            throw new RuntimeException('An owned site can only be created inside a rolled-back lifecycle.');
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        $record = new SiteRecord();
        $record->groupId = $primarySite->groupId;
        $record->primary = false;
        $record->enabled = 'true';
        $record->name = 'Campaign Manager test site ' . $label;
        $record->handle = strtolower($this->nextTestMarker('cm_test_site_', $label));
        $record->language = $primarySite->language;
        $record->hasUrls = false;
        $record->baseUrl = null;
        $record->sortOrder = (int)(new Query())->from(Table::SITES)->where(['dateDeleted' => null])->max('[[sortOrder]]') + 1;
        $record->uid = StringHelper::UUID();

        if (!$record->save(false) || $record->id === null) {
            throw new RuntimeException('The owned site row could not be written.');
        }

        $this->ownedSiteIds[] = (int)$record->id;
        Craft::$app->getSites()->refreshSites();

        // Craft lists the valid permissions once per process, so the new
        // site's edit permission would be dropped as unknown when granted.
        // A fresh service lists it; the original is put back after rollback.
        $this->originalUserPermissions ??= Craft::$app->get('userPermissions');
        Craft::$app->set('userPermissions', Craft::createObject(UserPermissions::class));

        return (int)$record->id;
    }

    /**
     * Trash an owned site with the statement Craft's site handler runs when
     * a site is deleted. The row stays, with a deletion date.
     */
    protected function softDeleteOwnedSite(int $siteId): void
    {
        $this->requireOwnedSite($siteId);
        Craft::$app->getDb()->createCommand()->softDelete(Table::SITES, ['id' => $siteId])->execute();
        Craft::$app->getSites()->refreshSites();
    }

    /**
     * Remove an owned trashed site with the statement Craft's garbage
     * collector runs for trashed site rows, limited to that one row.
     */
    protected function hardDeleteOwnedSite(int $siteId): void
    {
        $this->requireOwnedSite($siteId);
        if ((new Query())->from(Table::SITES)->where(['id' => $siteId])->andWhere(['not', ['dateDeleted' => null]])->exists() === false) {
            throw new RuntimeException('Only a trashed owned site can be garbage collected.');
        }

        Db::delete(Table::SITES, ['id' => $siteId]);
        Craft::$app->getSites()->refreshSites();
    }

    /**
     * The site rows of an activity log as stored, a deleted site as null,
     * null first.
     *
     * @return list<int|null>
     */
    protected function logSiteRows(int $logId): array
    {
        $rows = array_map(
            static fn(mixed $siteId): ?int => $siteId === null ? null : (int)$siteId,
            (new Query())
                ->select('siteId')
                ->from(ActivityLogSiteRecord::tableName())
                ->where(['logId' => $logId])
                ->column(),
        );
        usort($rows, static fn(?int $a, ?int $b): int => ($a ?? PHP_INT_MIN) <=> ($b ?? PHP_INT_MIN));

        return $rows;
    }

    private function requireOwnedSite(int $siteId): void
    {
        if (!in_array($siteId, $this->ownedSiteIds, true)) {
            throw new RuntimeException("Site {$siteId} is not owned by this test and will not be touched.");
        }
    }

    /**
     * Act as a visitor who is not logged in, the way a public Twig template
     * or service call sees the request.
     */
    protected function actAsAnonymousVisitor(): void
    {
        $webUser = new class() extends \craft\console\User {
        };
        Craft::$app->set('user', $webUser);
        Craft::$app->getSites()->refreshSites();
        $this->syncTwigCurrentUser(null);

        self::assertNull(Craft::$app->getUser()->getIdentity());
    }

    /**
     * Point the `currentUser` Twig global at the acting user.
     *
     * Craft resolves that global once per Twig environment from whoever was
     * logged in at the first render, so a control panel template rendered
     * later under another identity would still see the first one.
     */
    private function syncTwigCurrentUser(?User $user): void
    {
        Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_CP)->addGlobal('currentUser', $user);
    }

    /**
     * Replace the sites and plugin permissions a user holds.
     *
     * @param list<int> $siteIds
     * @param list<string> $permissions
     */
    protected function allowSites(User $user, array $siteIds, array $permissions): void
    {
        $sitePermissions = [];
        foreach ($siteIds as $siteId) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site === null) {
                throw new RuntimeException("Site {$siteId} does not exist.");
            }
            $sitePermissions[] = 'editSite:' . $site->uid;
        }

        $this->grantPermissions($user, array_merge(['accessCp'], $sitePermissions, $permissions));
        Craft::$app->getSites()->refreshSites();
    }

    /**
     * Return the first $count site IDs of the project, primary site first.
     *
     * @return list<int>
     */
    protected function siteIds(int $count): array
    {
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $siteIds = [$primarySiteId];

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if ((int)$siteId !== $primarySiteId) {
                $siteIds[] = (int)$siteId;
            }
        }

        if (count($siteIds) < $count) {
            self::markTestSkipped("This behavior needs a project with at least {$count} sites.");
        }

        return array_slice($siteIds, 0, $count);
    }

    protected function siteHandle(int $siteId): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null) {
            throw new RuntimeException("Site {$siteId} does not exist.");
        }

        return $site->handle;
    }

    /**
     * Save a campaign that is enabled on every site.
     */
    protected function seedOwnedCampaign(): Campaign
    {
        $campaign = $this->seedCampaign($this->seedForm(['comment']));
        $this->ownedCampaignIds[] = (int)$campaign->id;

        return $campaign;
    }

    /**
     * Save a recipient of the campaign on the given site.
     *
     * @param array<string, mixed> $attributes
     */
    protected function seedOwnedRecipient(Campaign $campaign, int $siteId, array $attributes = []): RecipientRecord
    {
        $recipient = $this->seedRecipient($campaign, array_merge([
            'siteId' => $siteId,
            'email' => 'site-' . $siteId . '-' . bin2hex(random_bytes(4)) . '@example.test',
        ], $attributes));
        $this->ownedRecipientIds[] = (int)$recipient->id;

        return $recipient;
    }

    /**
     * Track a recipient that an action under test created.
     */
    protected function ownRecipient(int $recipientId): void
    {
        $this->ownedRecipientIds[] = $recipientId;
        $this->trackRecipientForCleanup($recipientId);
    }

    /**
     * Track a campaign that an action under test created.
     */
    protected function ownCampaign(int $campaignId): void
    {
        $this->ownedCampaignIds[] = $campaignId;
        $this->trackCampaignForCleanup($campaignId);
    }

    protected function campaignsController(): CapturingCampaignsController
    {
        return new CapturingCampaignsController('campaigns', \lindemannrock\campaignmanager\CampaignManager::$plugin);
    }

    protected function recipientsController(): CapturingRecipientsController
    {
        return new CapturingRecipientsController('recipients', \lindemannrock\campaignmanager\CampaignManager::$plugin);
    }

    protected function activityLogsController(): CapturingActivityLogsController
    {
        return new CapturingActivityLogsController('logs', \lindemannrock\campaignmanager\CampaignManager::$plugin);
    }

    /**
     * Write an activity log as the acting user.
     *
     * @param list<int> $siteIds
     * @param array<string, mixed> $details
     */
    protected function writeOwnedLog(string $siteScope, array $siteIds, string $action = 'recipient_added', array $details = [], ?string $summary = null): ActivityLogRecord
    {
        $userId = (int)Craft::$app->getUser()->getId();
        if (!in_array($userId, $this->ownedUserIds, true)) {
            throw new RuntimeException('Logs may only be written as a user the test owns.');
        }

        \lindemannrock\campaignmanager\CampaignManager::$plugin->activityLogs->log($action, [
            'source' => 'manual',
            'summary' => $summary ?? $action,
            'details' => $details,
            'siteScope' => $siteScope,
            'siteIds' => $siteIds,
        ]);

        $log = $this->latestOwnedLog($action);
        if ($log === null) {
            throw new RuntimeException('The activity log was not written.');
        }

        return $log;
    }

    /**
     * The newest activity log of the given action written by an owned user.
     */
    protected function latestOwnedLog(string $action): ?ActivityLogRecord
    {
        if ($this->ownedUserIds === []) {
            return null;
        }

        /** @var ActivityLogRecord|null $log */
        $log = ActivityLogRecord::find()
            ->where(['userId' => $this->ownedUserIds, 'action' => $action])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $log;
    }

    /**
     * The sites an activity log covers, by ID.
     *
     * @return list<int>
     */
    protected function logSiteIds(int $logId): array
    {
        return array_map('intval', (new Query())
            ->select('siteId')
            ->from(ActivityLogSiteRecord::tableName())
            ->where(['logId' => $logId])
            ->orderBy('siteId')
            ->column());
    }

    /**
     * Assert that the action is refused without telling the user whether the
     * campaign, recipient or site exists.
     */
    protected function assertDenied(callable $action, string $message = ''): void
    {
        try {
            $action();
        } catch (ForbiddenHttpException|NotFoundHttpException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail($message !== '' ? $message : 'Expected the action to be denied.');
    }

    /**
     * Count the queued jobs that carry one of the owned campaigns.
     */
    protected function ownedQueueJobCount(): int
    {
        return count($this->ownedQueueJobIds());
    }

    /**
     * Count the activity logs written by the owned users.
     */
    protected function ownedActivityLogCount(): int
    {
        if ($this->ownedUserIds === []) {
            return 0;
        }

        return (int)ActivityLogRecord::find()->where(['userId' => $this->ownedUserIds])->count();
    }

    /**
     * Return everything the owned users' activity logs say, as one string.
     */
    protected function ownedActivityLogText(): string
    {
        if ($this->ownedUserIds === []) {
            return '';
        }

        $rows = (new Query())
            ->select(['summary', 'details'])
            ->from(ActivityLogRecord::tableName())
            ->where(['userId' => $this->ownedUserIds])
            ->all();

        return (string)json_encode($rows);
    }

    /**
     * Read the persisted site content of a campaign.
     *
     * @return array<string, mixed>
     */
    protected function persistedCampaignState(Campaign $campaign): array
    {
        return [
            'campaign' => (new Query())
                ->from('{{%campaignmanager_campaigns}}')
                ->where(['id' => $campaign->id])
                ->one(),
            'content' => (new Query())
                ->from('{{%campaignmanager_campaigns_content}}')
                ->where(['campaignId' => $campaign->id])
                ->orderBy('siteId')
                ->all(),
            'sites' => (new Query())
                ->select(['siteId', 'title', 'enabled'])
                ->from('{{%elements_sites}}')
                ->where(['elementId' => $campaign->id])
                ->orderBy('siteId')
                ->all(),
            'deleted' => (new Query())
                ->select('dateDeleted')
                ->from('{{%elements}}')
                ->where(['id' => $campaign->id])
                ->scalar(),
        ];
    }

    /**
     * @return list<int>
     */
    private function ownedQueueJobIds(): array
    {
        if ($this->ownedCampaignIds === []) {
            return [];
        }

        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            throw new RuntimeException('The queue does not store its jobs in the database.');
        }

        $jobIds = [];
        $rows = (new Query())
            ->select(['id', 'job'])
            ->from($queue->tableName)
            ->where(['like', 'job', 'campaignmanager'])
            ->all();

        foreach ($rows as $row) {
            $job = is_resource($row['job']) ? (string)stream_get_contents($row['job']) : (string)$row['job'];
            foreach ($this->ownedCampaignIds as $campaignId) {
                if (str_contains($job, '"campaignId";i:' . $campaignId . ';')) {
                    $jobIds[] = (int)$row['id'];
                    break;
                }
            }
        }

        return $jobIds;
    }

    private function cleanupOwnedQueueJobs(): void
    {
        $jobIds = $this->ownedQueueJobIds();
        if ($jobIds === []) {
            return;
        }

        $queue = Craft::$app->getQueue();
        if ($queue instanceof Queue) {
            Craft::$app->getDb()->createCommand()
                ->delete($queue->tableName, ['id' => $jobIds])
                ->execute();
        }
    }

    private function cleanupOwnedActivityLogs(): void
    {
        $conditions = ['or'];
        if ($this->ownedUserIds !== []) {
            $conditions[] = ['userId' => $this->ownedUserIds];
        }
        if ($this->ownedCampaignIds !== []) {
            $conditions[] = ['campaignId' => $this->ownedCampaignIds];
        }
        if ($this->ownedRecipientIds !== []) {
            $conditions[] = ['recipientId' => $this->ownedRecipientIds];
        }

        if (count($conditions) === 1) {
            return;
        }

        $logIds = array_map('intval', ActivityLogRecord::find()->select('id')->where($conditions)->column());
        ActivityLogRecord::deleteAll($conditions);

        // The site rows go with their log through the foreign key
        if ($logIds !== [] && $this->countRows(ActivityLogSiteRecord::tableName(), ['logId' => $logIds]) !== 0) {
            throw new RuntimeException('Owned activity logs left site rows behind.');
        }
    }

    private function cleanupOwnedWidgets(): void
    {
        if ($this->ownedUserIds === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%widgets}}', ['userId' => $this->ownedUserIds])
            ->execute();
    }

    private function restoreRequestState(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalUser !== null) {
            Craft::$app->set('user', $this->originalUser);
            $this->originalUser = null;
            $this->syncTwigCurrentUser(Craft::$app->getUser()->getIdentity());
        }
        if ($this->originalTemplateMode !== null) {
            Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
            $this->originalTemplateMode = null;
        }

        if ($this->hadRequestMethod) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }

    private function assertNoOwnedResidue(): void
    {
        if ($this->ownedQueueJobIds() !== []) {
            throw new RuntimeException('Owned queue jobs still exist after cleanup.');
        }
        if ($this->ownedActivityLogCount() !== 0) {
            throw new RuntimeException('Owned activity logs still exist after cleanup.');
        }
        if ($this->ownedRecipientIds !== []
            && $this->countRows(RecipientRecord::tableName(), ['id' => $this->ownedRecipientIds]) !== 0
        ) {
            throw new RuntimeException('Owned recipients still exist after cleanup.');
        }
        if ($this->ownedUserIds !== []
            && Craft::$app->getDb()->tableExists(self::SEARCH_MANAGER_PENDING_SYNCS)
            && $this->countRows(self::SEARCH_MANAGER_PENDING_SYNCS, [
                'elementType' => User::class,
                'elementId' => $this->ownedUserIds,
            ]) !== 0
        ) {
            throw new RuntimeException('Owned users left Search Manager pending-sync rows behind.');
        }
    }

    /**
     * Stop Search Manager from queueing index work for elements saved or
     * deleted during the test. Only the in-memory setting changes; nothing
     * is written to the database.
     */
    private function suspendSearchManagerAutoIndex(): void
    {
        $settings = Craft::$app->getPlugins()->getPlugin('search-manager')?->getSettings();
        if ($settings === null || !property_exists($settings, 'autoIndex')) {
            return;
        }

        $this->searchManagerSettings = $settings;
        $this->originalSearchManagerAutoIndex = (bool)$settings->autoIndex;
        $settings->autoIndex = false;
    }

    private function restoreSearchManagerAutoIndex(): void
    {
        if ($this->searchManagerSettings !== null && $this->originalSearchManagerAutoIndex !== null) {
            $this->searchManagerSettings->autoIndex = $this->originalSearchManagerAutoIndex;
        }

        $this->searchManagerSettings = null;
        $this->originalSearchManagerAutoIndex = null;
    }
}
