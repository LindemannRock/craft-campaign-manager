<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * Activity logs service
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\services;

use Craft;
use craft\base\Component;
use craft\db\ActiveQuery;
use craft\db\Query;
use craft\helpers\Db;
use InvalidArgumentException;
use lindemannrock\campaignmanager\helpers\SiteAccessHelper;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\ActivityLogSiteRecord;

/**
 * Activity logs service
 *
 * Every log records which sites it covers, so that a control panel user only
 * sees the activity of sites they may edit:
 *
 * - `sites`: exactly the sites listed in the log's site rows
 * - `all`: every site of the project
 * - `unknown`: not recorded, which is the case for logs written before site
 *   scope existed
 *
 * @since 5.4.0
 */
class ActivityLogsService extends Component
{
    /**
     * Record an activity log
     *
     * `siteScope` says which sites the logged operation covered. A `sites`
     * scope lists them in `siteIds`; `all` and `unknown` carry no site IDs.
     * A log without a scope is stored as `unknown`.
     *
     * @param string $action
     * @param array{
     *   summary?: string,
     *   details?: array,
     *   campaignId?: int|null,
     *   recipientId?: int|null,
     *   userId?: int|null,
     *   source?: string,
     *   siteScope?: string,
     *   siteIds?: array<int>,
     * } $context
     * @throws InvalidArgumentException if the site scope and site IDs do not fit together
     */
    public function log(string $action, array $context = []): void
    {
        $settings = Craft::$app->getPlugins()->getPlugin('campaign-manager')?->getSettings();
        if (!$settings || !($settings->enableActivityLogs ?? true)) {
            return;
        }

        [$siteScope, $siteIds] = $this->normalizeSiteScope($context['siteScope'] ?? null, $context['siteIds'] ?? []);

        $userId = $context['userId'] ?? Craft::$app->getUser()->getId();

        $record = new ActivityLogRecord([
            'userId' => $userId ?: null,
            'campaignId' => $context['campaignId'] ?? null,
            'recipientId' => $context['recipientId'] ?? null,
            'action' => $action,
            'source' => $context['source'] ?? 'system',
            'summary' => $context['summary'] ?? null,
            'details' => $this->encodeDetails($context['details'] ?? null),
            'siteScope' => $siteScope,
        ]);

        // The log and its site rows are written together, so a log never
        // exists without the sites that decide who may see it.
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            $record->save(false);
            $this->insertSiteRows((int)$record->id, $siteIds);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        if ($settings->activityAutoTrimLogs ?? false) {
            $this->trimLogs();
        }
    }

    /**
     * Whether the current user may see activity of every site.
     *
     * That takes the permission to view activity logs plus the right to edit
     * every site the project currently has. Logs whose sites are unknown or
     * that cover every site are only shown to such a user.
     *
     * @since 5.16.0
     */
    public function canViewEverySite(): bool
    {
        return Craft::$app->getUser()->checkPermission('campaignManager:viewActivityLogs')
            && SiteAccessHelper::canEditEverySite();
    }

    /**
     * Whether the current user may clear the activity logs.
     *
     * Clearing removes the logs of every site, so it takes the permission to
     * clear logs plus the right to edit every site the project currently has.
     *
     * @since 5.16.0
     */
    public function canClear(): bool
    {
        return Craft::$app->getUser()->checkPermission('campaignManager:clearActivityLogs')
            && SiteAccessHelper::canEditEverySite();
    }

    /**
     * Query the activity logs the current user may see.
     *
     * A user who may edit every site sees every log. Any other user sees only
     * logs whose scope is `sites`, that list at least one site, and whose
     * every site they may edit. Logs with an `all` or `unknown` scope stay
     * hidden from them. A site that was deleted after the log was written is
     * kept as a row without a site ID and counts as a site nobody but an
     * all-site user may edit, so the log stays hidden. The query's table is
     * aliased `log`.
     *
     * @since 5.16.0
     */
    public function visibleLogsQuery(): ActiveQuery
    {
        $query = ActivityLogRecord::find()->alias('log');

        if ($this->canViewEverySite()) {
            return $query;
        }

        $editableSiteIds = SiteAccessHelper::editableSiteIds();
        if ($editableSiteIds === []) {
            return $query->andWhere('0=1');
        }

        $siteTable = ActivityLogSiteRecord::tableName();

        return $query
            ->andWhere(['log.siteScope' => ActivityLogRecord::SCOPE_SITES])
            ->andWhere(['exists', (new Query())
                ->from(['covered' => $siteTable])
                ->where('[[covered.logId]] = [[log.id]]'),
            ])
            ->andWhere(['not exists', (new Query())
                ->from(['outside' => $siteTable])
                ->where('[[outside.logId]] = [[log.id]]')
                // A deleted site (null) is outside every editable set; a plain
                // NOT IN would be null for it and let the log through
                ->andWhere(['or', ['outside.siteId' => null], ['not in', 'outside.siteId', $editableSiteIds]]),
            ]);
    }

    /**
     * Trim activity logs based on retention and limit
     */
    public function trimLogs(): void
    {
        $settings = Craft::$app->getPlugins()->getPlugin('campaign-manager')?->getSettings();
        if (!$settings) {
            return;
        }

        $table = ActivityLogRecord::tableName();
        $retentionDays = (int)($settings->activityLogsRetention ?? 0);
        $limit = (int)($settings->activityLogsLimit ?? 0);

        if ($retentionDays > 0) {
            $cutoff = (new \DateTime("-{$retentionDays} days"))->format('Y-m-d H:i:s');
            Db::delete($table, ['<', 'dateCreated', $cutoff]);
        }

        if ($limit > 0) {
            $idsToDelete = (new Query())
                ->select(['id'])
                ->from($table)
                ->orderBy(['dateCreated' => SORT_DESC])
                ->offset($limit)
                ->column();

            if (!empty($idsToDelete)) {
                Db::delete($table, ['id' => $idsToDelete]);
            }
        }
    }

    /**
     * Write the site rows of a log
     *
     * @param list<int> $siteIds
     */
    protected function insertSiteRows(int $logId, array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()->batchInsert(
            ActivityLogSiteRecord::tableName(),
            ['logId', 'siteId'],
            array_map(static fn(int $siteId): array => [$logId, $siteId], $siteIds),
        )->execute();
    }

    /**
     * Check a site scope and its site IDs against each other.
     *
     * @param array<int|string> $siteIds
     * @return array{0: string, 1: list<int>}
     */
    private function normalizeSiteScope(?string $siteScope, array $siteIds): array
    {
        $siteScope ??= ActivityLogRecord::SCOPE_UNKNOWN;

        if (!in_array($siteScope, [ActivityLogRecord::SCOPE_SITES, ActivityLogRecord::SCOPE_ALL, ActivityLogRecord::SCOPE_UNKNOWN], true)) {
            throw new InvalidArgumentException("Unknown activity log site scope \"{$siteScope}\".");
        }

        if ($siteScope !== ActivityLogRecord::SCOPE_SITES) {
            if ($siteIds !== []) {
                throw new InvalidArgumentException("An activity log with the \"{$siteScope}\" site scope cannot list sites.");
            }

            return [$siteScope, []];
        }

        $normalized = [];
        $allSiteIds = SiteAccessHelper::allSiteIds();
        foreach ($siteIds as $siteId) {
            if (!is_int($siteId) && !(is_string($siteId) && ctype_digit($siteId))) {
                throw new InvalidArgumentException('Activity log site IDs must be integers.');
            }
            $siteId = (int)$siteId;
            if (!in_array($siteId, $allSiteIds, true)) {
                throw new InvalidArgumentException("Site {$siteId} does not exist, so an activity log cannot cover it.");
            }
            $normalized[$siteId] = $siteId;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('An activity log with the "sites" site scope must list at least one site.');
        }

        return [$siteScope, array_values($normalized)];
    }

    /**
     * Encode details to JSON
     */
    private function encodeDetails(?array $details): ?string
    {
        if (empty($details)) {
            return null;
        }

        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }
}
