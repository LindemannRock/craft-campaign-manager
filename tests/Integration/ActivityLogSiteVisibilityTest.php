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
use craft\elements\User;
use craft\errors\MissingComponentException;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\ActivityLogsController;
use lindemannrock\campaignmanager\helpers\SiteAccessHelper;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\ActivityLogSiteRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\ActivityLogsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The activity log page shows a user only the logs of sites they may edit,
 * and the logs of every site may only be cleared by a user who may edit
 * every site.
 *
 * @since 5.16.0
 */
#[CoversClass(ActivityLogsController::class)]
#[CoversClass(ActivityLogsService::class)]
#[CoversClass(SiteAccessHelper::class)]
final class ActivityLogSiteVisibilityTest extends SiteRestrictedUserTestCase
{
    private const VIEW = ['campaignManager:viewLogs', 'campaignManager:viewActivityLogs'];
    private const VIEW_AND_CLEAR = ['campaignManager:viewLogs', 'campaignManager:viewActivityLogs', 'campaignManager:clearActivityLogs'];

    public function testASingleSiteLogIsShownOnlyToUsersWhoMayEditThatSite(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $marker = $this->marker();
        $this->actAsWriter();
        $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA], 'recipient_added', ['email' => $marker]);

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(1, $page['pagination']['totalCount']);
        self::assertStringContainsString($marker, (string)json_encode($page['logs']));

        $this->actAsUserRestrictedTo([$siteB], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(0, $page['pagination']['totalCount']);
        self::assertSame([], $page['logs']);
        self::assertStringNotContainsString($marker, (string)json_encode($page));

        self::assertSame([$siteA], $this->logSiteIds((int)$log->id));
    }

    public function testAMultiSiteLogNeedsEveryOneOfItsSites(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $marker = $this->marker();
        $this->actAsWriter();
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB], 'recipients_deleted', ['email' => $marker]);

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        self::assertSame(0, $this->indexVariables()['pagination']['totalCount']);

        $this->actAsUserRestrictedTo([$siteB, $siteC], self::VIEW);
        self::assertSame(0, $this->indexVariables()['pagination']['totalCount']);

        $this->actAsUserRestrictedTo([$siteA, $siteB], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(1, $page['pagination']['totalCount']);
        self::assertStringContainsString($marker, (string)json_encode($page['logs']));

        $this->actAsUserRestrictedTo([$siteA, $siteB, $siteC], self::VIEW);
        self::assertSame(1, $this->indexVariables()['pagination']['totalCount']);
    }

    public function testLogsOfEverySiteAndOfUnknownScopeAreShownOnlyToAllSiteUsers(): void
    {
        [$siteA] = $this->siteIds(2);
        $allMarker = $this->marker();
        $unknownMarker = $this->marker();
        $sitesMarker = $this->marker();
        $this->actAsWriter();
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_ALL, [], 'campaign_updated', ['title' => $allMarker]);
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_UNKNOWN, [], 'campaign_updated', ['title' => $unknownMarker]);
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA], 'recipient_added', ['email' => $sitesMarker]);

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(1, $page['pagination']['totalCount']);
        $json = (string)json_encode($page);
        self::assertStringContainsString($sitesMarker, $json);
        self::assertStringNotContainsString($allMarker, $json);
        self::assertStringNotContainsString($unknownMarker, $json);

        $this->actAsUserRestrictedTo($this->everySite(), self::VIEW);
        $page = $this->indexVariables();
        self::assertGreaterThanOrEqual(3, $page['pagination']['totalCount']);
        $json = (string)json_encode($page['logs']);
        self::assertStringContainsString($sitesMarker, $json);
        self::assertStringContainsString($allMarker, $json);
        self::assertStringContainsString($unknownMarker, $json);
    }

    public function testAUserWithoutAnEditableSiteSeesNoLogs(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsWriter();
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA]);
        $this->writeOwnedLog(ActivityLogRecord::SCOPE_ALL, []);

        $this->actAsUserRestrictedTo([], self::VIEW);
        $page = $this->indexVariables();

        self::assertSame(0, $page['pagination']['totalCount']);
        self::assertSame([], $page['logs']);
        self::assertSame(0, (int)CampaignManager::$plugin->activityLogs->visibleLogsQuery()->count());
    }

    public function testADeletedRecipientDoesNotLeakThroughTheLog(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $email = 'deleted-' . bin2hex(random_bytes(6)) . '@example.test';
        $recipient = $this->seedOwnedRecipient($campaign, $siteB, ['email' => $email]);

        $this->actAsUserRestrictedTo([$siteB], ['campaignManager:manageRecipients', 'campaignManager:deleteRecipients']);
        $this->request('POST', [], ['recipientIds' => [(string)$recipient->id]]);
        self::assertSame(1, $this->recipientsController()->actionBulkDelete()->data['count']);
        self::assertNull(RecipientRecord::findOne($recipient->id));
        $log = $this->latestOwnedLog('recipients_deleted');
        self::assertNotNull($log);
        self::assertSame([$siteB], $this->logSiteIds((int)$log->id));
        self::assertStringContainsString($email, (string)$log->details);

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(0, $page['pagination']['totalCount']);
        self::assertStringNotContainsString($email, (string)json_encode($page));

        $this->actAsUserRestrictedTo([$siteB], self::VIEW);
        self::assertStringContainsString($email, (string)json_encode($this->indexVariables()['logs']));
    }

    public function testADeletedSiteKeepsAMultiSiteLogHiddenFromRestrictedUsers(): void
    {
        [$siteA] = $this->siteIds(1);
        $marker = $this->marker();

        $this->withinRolledBackSiteLifecycle(function() use ($siteA, $marker): void {
            $siteB = $this->insertOwnedSite('b');
            $this->actAsWriter();
            $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB], 'recipients_deleted', ['email' => $marker]);
            $details = (string)$log->details;
            $expectedRows = [$siteA, $siteB];
            sort($expectedRows);
            self::assertSame($expectedRows, $this->logSiteRows((int)$log->id));

            $restricted = $this->actAsUserRestrictedTo([$siteA], self::VIEW);
            $allSite = $this->actAsUserRestrictedTo($this->everySite(), self::VIEW);

            // Both sites exist
            $this->assertLogHiddenFrom($restricted, $marker);
            $this->assertLogShownTo($allSite, $marker);

            // Craft trashes the site first; the row stays with a deletion date
            $this->softDeleteOwnedSite($siteB);
            self::assertNotContains($siteB, SiteAccessHelper::allSiteIds());
            self::assertSame($expectedRows, $this->logSiteRows((int)$log->id));
            $this->assertLogHiddenFrom($restricted, $marker);
            $this->assertLogShownTo($allSite, $marker);

            // Garbage collection removes the trashed row; the mapping stays as a null row
            $this->hardDeleteOwnedSite($siteB);
            $this->assertLogHiddenFrom($restricted, $marker);
            $this->assertLogShownTo($allSite, $marker);
            self::assertSame([null, $siteA], $this->logSiteRows((int)$log->id));

            self::assertSame($details, (string)ActivityLogRecord::findOne($log->id)?->details);
        });
    }

    public function testSeveralDeletedSitesKeepAMultiSiteLogHiddenFromRestrictedUsers(): void
    {
        [$siteA] = $this->siteIds(1);
        $marker = $this->marker();

        $this->withinRolledBackSiteLifecycle(function() use ($siteA, $marker): void {
            $siteB = $this->insertOwnedSite('b');
            $siteC = $this->insertOwnedSite('c');
            $this->actAsWriter();
            $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA, $siteB, $siteC], 'recipients_exported', ['email' => $marker]);

            $restricted = $this->actAsUserRestrictedTo([$siteA], self::VIEW);
            $allSite = $this->actAsUserRestrictedTo($this->everySite(), self::VIEW);

            foreach ([$siteB, $siteC] as $deletedSite) {
                $this->softDeleteOwnedSite($deletedSite);
                $this->hardDeleteOwnedSite($deletedSite);
            }

            $this->assertLogHiddenFrom($restricted, $marker);
            $this->assertLogShownTo($allSite, $marker);
            self::assertSame([null, null, $siteA], $this->logSiteRows((int)$log->id));
        });
    }

    public function testASitesLogWithoutAnySiteRowStaysHiddenFromRestrictedUsers(): void
    {
        [$siteA] = $this->siteIds(1);
        $marker = $this->marker();
        $this->actAsWriter();
        $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA], 'recipient_added', ['email' => $marker]);

        // The reader never trusts a sites log that lists no site
        ActivityLogSiteRecord::deleteAll(['logId' => $log->id]);
        self::assertSame([], $this->logSiteIds((int)$log->id));

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $page = $this->indexVariables();
        self::assertSame(0, $page['pagination']['totalCount']);
        self::assertStringNotContainsString($marker, (string)json_encode($page));

        $this->actAsUserRestrictedTo($this->everySite(), self::VIEW);
        self::assertStringContainsString($marker, (string)json_encode($this->indexVariables()['logs']));
    }

    public function testSearchAndPagingCountOnlyVisibleLogs(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $needle = 'needle' . bin2hex(random_bytes(4));
        $limit = max(1, (int)CampaignManager::$plugin->getSettings()->itemsPerPage);
        $visible = $limit + 2;
        $this->actAsWriter();
        foreach (array_merge(array_fill(0, $visible, $siteA), array_fill(0, 3, $siteB)) as $siteId) {
            $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteId], 'recipient_added', ['email' => $needle . '@example.test']);
        }

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $pages = [];
        foreach ([1, 2, 3] as $pageNumber) {
            $pages[] = $this->indexVariables(['search' => $needle, 'page' => (string)$pageNumber]);
        }

        self::assertSame([$visible, $visible, $visible], array_column(array_column($pages, 'pagination'), 'totalCount'));
        self::assertSame([$limit, 2, 0], array_map('count', array_column($pages, 'logs')));
        self::assertSame($visible, mb_substr_count((string)json_encode(array_column($pages, 'logs')), $needle));

        self::assertSame(0, $this->indexVariables(['search' => 'no-such-' . $needle])['pagination']['totalCount']);
    }

    public function testClearingIsRefusedForUsersWhoCannotEditEverySite(): void
    {
        [$siteA] = $this->siteIds(2);
        $this->actAsWriter();
        $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA]);
        $totalBefore = $this->countRows(ActivityLogRecord::tableName());

        foreach ([[$siteA], []] as $editable) {
            $this->actAsUserRestrictedTo($editable, self::VIEW_AND_CLEAR);
            self::assertFalse($this->indexVariables()['canClear']);

            $this->request('POST');
            $this->assertDenied(fn() => $this->activityLogsController()->actionClear());

            self::assertSame($totalBefore, $this->countRows(ActivityLogRecord::tableName()));
            self::assertNotNull(ActivityLogRecord::findOne($log->id));
            self::assertSame([$siteA], $this->logSiteIds((int)$log->id));
        }
    }

    public function testAnAllSiteUserClearsTheLogsOfEverySite(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsWriter();
        $log = $this->writeOwnedLog(ActivityLogRecord::SCOPE_SITES, [$siteA]);
        $totalBefore = $this->countRows(ActivityLogRecord::tableName());
        $siteRowsBefore = $this->countRows(ActivityLogSiteRecord::tableName());
        self::assertGreaterThan(0, $siteRowsBefore);

        $this->actAsUserRestrictedTo($this->everySite(), self::VIEW_AND_CLEAR);
        self::assertTrue($this->indexVariables()['canClear']);

        // The clear is real but rolled back, so the project's own logs survive
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $this->request('POST');
            try {
                $this->activityLogsController()->actionClear();
                self::fail('Expected the cleared logs to be reported through the session.');
            } catch (MissingComponentException $e) {
                self::assertStringContainsString('Session', $e->getMessage());
            }

            self::assertSame(0, $this->countRows(ActivityLogRecord::tableName()));
            self::assertSame(0, $this->countRows(ActivityLogSiteRecord::tableName()));
        } finally {
            $transaction->rollBack();
        }

        self::assertSame($totalBefore, $this->countRows(ActivityLogRecord::tableName()));
        self::assertSame($siteRowsBefore, $this->countRows(ActivityLogSiteRecord::tableName()));
        self::assertSame([$siteA], $this->logSiteIds((int)$log->id));
    }

    /**
     * Act as a user who may edit every site and write logs.
     */
    private function actAsWriter(): void
    {
        $this->actAsUserRestrictedTo($this->everySite(), []);
    }

    /**
     * @return list<int>
     */
    private function everySite(): array
    {
        return SiteAccessHelper::allSiteIds();
    }

    private function marker(): string
    {
        return 'marker-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    /**
     * Assert that nothing of the marked log reaches the user's page.
     */
    private function assertLogHiddenFrom(User $user, string $marker): void
    {
        $this->actAs($user);
        self::assertStringNotContainsString($marker, (string)json_encode($this->indexVariables()));
    }

    /**
     * Assert that the marked log is listed on the user's page.
     */
    private function assertLogShownTo(User $user, string $marker): void
    {
        $this->actAs($user);
        self::assertStringContainsString($marker, (string)json_encode($this->indexVariables()['logs']));
    }

    /**
     * @param array<string, string> $queryParams
     * @return array<string, mixed>
     */
    private function indexVariables(array $queryParams = []): array
    {
        $this->request('GET', $queryParams);

        return $this->activityLogsController()->actionIndex()->data['variables'];
    }
}
