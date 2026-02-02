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
use craft\db\Query;
use craft\helpers\Db;
use lindemannrock\campaignmanager\records\ActivityLogRecord;

/**
 * Activity logs service
 *
 * @since 5.4.0
 */
class ActivityLogsService extends Component
{
    /**
     * Record an activity log
     *
     * @param string $action
     * @param array{
     *   summary?: string,
     *   details?: array,
     *   campaignId?: int|null,
     *   recipientId?: int|null,
     *   userId?: int|null,
     *   source?: string,
     * } $context
     * @since 5.4.0
     */
    public function log(string $action, array $context = []): void
    {
        $settings = Craft::$app->getPlugins()->getPlugin('campaign-manager')?->getSettings();
        if (!$settings || !($settings->enableActivityLogs ?? true)) {
            return;
        }

        $userId = $context['userId'] ?? Craft::$app->getUser()->getId();

        $record = new ActivityLogRecord([
            'userId' => $userId ?: null,
            'campaignId' => $context['campaignId'] ?? null,
            'recipientId' => $context['recipientId'] ?? null,
            'action' => $action,
            'source' => $context['source'] ?? 'system',
            'summary' => $context['summary'] ?? null,
            'details' => $this->encodeDetails($context['details'] ?? null),
        ]);

        $record->save(false);

        if ($settings->activityAutoTrimLogs ?? false) {
            $this->trimLogs();
        }
    }

    /**
     * Trim activity logs based on retention and limit
     *
     * @since 5.4.0
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
     * Encode details to JSON
     *
     * @since 5.4.0
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
