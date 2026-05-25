<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * Controller for viewing activity logs
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\LoggingLibrary;
use yii\web\Response;

/**
 * Activity Logs Controller
 *
 * @since 5.4.0
 */
class ActivityLogsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = false;

    /**
     * Activity logs index page (placeholder)
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:viewActivityLogs');

        $user = Craft::$app->getUser();
        $settings = Craft::$app->getPlugins()->getPlugin('campaign-manager')->getSettings();
        $request = Craft::$app->getRequest();

        // ---- Param parsing + allowlist validation -------------------------
        // `date` maps to the underlying `dateCreated` column; the other four
        // map to their natural columns. Off-list values snap to `date`.
        $validSortFields = ['date', 'user', 'actionLabel', 'campaignName', 'source'];
        $sort = (string) $request->getParam('sort', 'date');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'date';
        }
        $dir = strtolower((string) $request->getParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) ($settings->itemsPerPage ?? 50));
        $offset = ($page - 1) * $limit;

        // Map sort key → underlying SQL column. `user` and `campaignName` sort
        // by the foreign-key id columns rather than the joined names — close
        // enough for grouping, avoids a JOIN. `actionLabel` sorts by `action`
        // (the machine slug), which keeps action types grouped together.
        $sortColumn = match ($sort) {
            'user' => 'userId',
            'actionLabel' => 'action',
            'campaignName' => 'campaignId',
            'source' => 'source',
            default => 'dateCreated',
        };
        $direction = $dir === 'asc' ? SORT_ASC : SORT_DESC;

        $query = ActivityLogRecord::find()->orderBy([$sortColumn => $direction]);
        $totalCount = $query->count();

        /** @var ActivityLogRecord[] $records */
        $records = $query->offset($offset)->limit($limit)->all();
        $items = [];

        // Pre-scan records + decoded details to collect every ID we'll need,
        // then batch-fetch users / campaigns / recipients once. Replaces what
        // used to be up to ~750 per-page queries with three batched ones.
        $decodedDetails = [];
        $userIds = [];
        $campaignIds = [];
        $recipientIds = [];

        foreach ($records as $i => $record) {
            if ($record->userId) {
                $userIds[(int)$record->userId] = true;
            }
            if ($record->campaignId) {
                $campaignIds[(int)$record->campaignId] = true;
            }
            if ($record->recipientId) {
                $recipientIds[(int)$record->recipientId] = true;
            }

            $details = $record->details ? json_decode($record->details, true) : [];
            if (!is_array($details)) {
                $details = [];
            }
            $decodedDetails[$i] = $details;

            if (!empty($details['triggeredByUserId'])) {
                $userIds[(int)$details['triggeredByUserId']] = true;
            }
            if (!empty($details['recipientIds']) && is_array($details['recipientIds'])) {
                // Cap at 10 per source record — matches the existing limit(10)
                // that materialized details.recipients (avoids blowing memory
                // on a "recipients deleted" log that references 10k+ ids).
                $firstTen = array_slice($details['recipientIds'], 0, 10);
                foreach ($firstTen as $rid) {
                    if ((int)$rid > 0) {
                        $recipientIds[(int)$rid] = true;
                    }
                }
            }
            if (!empty($details['recipients']) && is_array($details['recipients'])) {
                foreach ($details['recipients'] as $r) {
                    if (is_array($r) && !empty($r['campaignId'])) {
                        $campaignIds[(int)$r['campaignId']] = true;
                    }
                }
            }
        }

        $userMap = [];
        if ($userIds !== []) {
            $users = User::find()->id(array_keys($userIds))->status(null)->all();
            foreach ($users as $userElement) {
                $userMap[$userElement->id] = $userElement;
            }
        }

        $campaignMap = [];
        if ($campaignIds !== []) {
            $campaigns = Campaign::find()->id(array_keys($campaignIds))->status(null)->all();
            foreach ($campaigns as $campaign) {
                $campaignMap[$campaign->id] = $campaign;
            }
        }

        $recipientMap = [];
        if ($recipientIds !== []) {
            /** @var RecipientRecord[] $recipientRecords */
            $recipientRecords = RecipientRecord::find()->where(['id' => array_keys($recipientIds)])->all();
            foreach ($recipientRecords as $recipientRecord) {
                $recipientMap[$recipientRecord->id] = $recipientRecord;
            }
        }

        foreach ($records as $i => $record) {
            $userName = $record->userId && isset($userMap[$record->userId])
                ? $userMap[$record->userId]->username
                : null;

            $details = $this->enrichDetails($decodedDetails[$i], $userMap, $campaignMap, $recipientMap);

            $campaignName = null;
            $campaignUrl = null;
            if ($record->campaignId && isset($campaignMap[$record->campaignId])) {
                $campaign = $campaignMap[$record->campaignId];
                $campaignName = $campaign->title;
                $campaignUrl = $campaign->getCpEditUrl();
            }

            $recipientLabel = null;
            if ($record->recipientId && isset($recipientMap[$record->recipientId])) {
                $recipient = $recipientMap[$record->recipientId];
                $recipientLabel = $recipient->name ?: ($recipient->email ?: $recipient->sms);
            }
            if (!$recipientLabel && !empty($details['count']) && $record->action === 'recipients_deleted') {
                $recipientLabel = Craft::t('campaign-manager', '{count} recipients', [
                    'count' => (int)$details['count'],
                ]);
            }

            $items[] = [
                'date' => DateFormatHelper::formatDatetime($record->dateCreated),
                'user' => $userName ?? Craft::t('campaign-manager', 'System'),
                'action' => $record->action,
                'actionLabel' => $this->formatActionLabel($record->action),
                'summary' => $record->summary ?? '-',
                'source' => $record->source,
                'campaignName' => $campaignName,
                'campaignUrl' => $campaignUrl,
                'recipientLabel' => $recipientLabel,
                'details' => $details,
            ];
        }

        $logMenuItems = null;
        $logMenuLabel = null;

        if (class_exists(LoggingLibrary::class)) {
            $config = LoggingLibrary::getConfig('campaign-manager');
            $logMenuItems = $config['logMenuItems'] ?? null;
            $logMenuLabel = $config['logMenuLabel'] ?? null;

            // Filter out 'system' item if system log viewer is disabled
            if ($logMenuItems && !($config['enableLogViewer'] ?? false)) {
                unset($logMenuItems['system']);
            }
        }

        return $this->renderTemplate('campaign-manager/logs/activity', [
            'logMenuItems' => $logMenuItems,
            'logMenuLabel' => $logMenuLabel,
            'logs' => $items,
            'sort' => $sort,
            'dir' => $dir,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
            ],
            'canClear' => $user->checkPermission('campaignManager:clearActivityLogs'),
            'activityLogsEnabled' => (bool)($settings->enableActivityLogs ?? true),
        ]);
    }

    /**
     * Clear activity logs
     *
     * @return Response
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireLogin();
        $this->requirePermission('campaignManager:clearActivityLogs');

        ActivityLogRecord::deleteAll();

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Activity logs cleared successfully.'));

        return $this->asJson([
            'success' => true,
        ]);
    }

    /**
     * Format action key into human-friendly label
     */
    private function formatActionLabel(string $action): string
    {
        return match ($action) {
            'recipient_added' => Craft::t('campaign-manager', 'Recipient added'),
            'recipient_deleted' => Craft::t('campaign-manager', 'Recipient deleted'),
            'recipients_deleted' => Craft::t('campaign-manager', 'Recipients deleted'),
            'recipients_imported' => Craft::t('campaign-manager', 'Recipients imported'),
            'campaign_invitations_queued' => Craft::t('campaign-manager', 'Invitations queued'),
            'campaigns_queued' => Craft::t('campaign-manager', 'Campaigns queued'),
            'campaign_batches_queued' => Craft::t('campaign-manager', 'Campaign batches queued'),
            'invitations_sent_batch' => Craft::t('campaign-manager', 'Invitation batch sent'),
            'campaign_created' => Craft::t('campaign-manager', 'Campaign created'),
            'campaign_updated' => Craft::t('campaign-manager', 'Campaign updated'),
            'campaign_deleted' => Craft::t('campaign-manager', 'Campaign deleted'),
            'recipients_exported' => Craft::t('campaign-manager', 'Recipients exported'),
            'campaign_recipients_exported' => Craft::t('campaign-manager', 'Campaign recipients exported'),
            default => ucwords(str_replace('_', ' ', $action)),
        };
    }

    /**
     * Enrich details with human-friendly context for display.
     *
     * Looks up users, campaigns, and recipients from the pre-fetched maps
     * built by {@see actionIndex()}. Site lookups stay inline because
     * `getSiteById()` is already an in-memory hit against Craft's site cache.
     *
     * @param array<string, mixed> $details
     * @param array<int, User> $userMap
     * @param array<int, Campaign> $campaignMap
     * @param array<int, RecipientRecord> $recipientMap
     * @return array<string, mixed>
     */
    private function enrichDetails(array $details, array $userMap, array $campaignMap, array $recipientMap): array
    {
        if (!empty($details['triggeredByUserId']) && empty($details['triggeredBy'])) {
            $triggeredByUserId = (int)$details['triggeredByUserId'];
            if ($triggeredByUserId > 0 && isset($userMap[$triggeredByUserId])) {
                $details['triggeredBy'] = $userMap[$triggeredByUserId]->username;
            }
        }

        if (!empty($details['siteId']) && empty($details['siteName'])) {
            $siteId = (int)$details['siteId'];
            if ($siteId > 0) {
                $details['siteName'] = Craft::$app->getSites()->getSiteById($siteId)?->name;
            }
        }

        if (!empty($details['siteIds']) && is_array($details['siteIds']) && empty($details['siteNames'])) {
            $details['siteNames'] = [];
            foreach ($details['siteIds'] as $siteId) {
                $siteId = (int)$siteId;
                if ($siteId > 0) {
                    $details['siteNames'][$siteId] = Craft::$app->getSites()->getSiteById($siteId)?->name;
                }
            }
        }

        if (!empty($details['recipientIds']) && is_array($details['recipientIds']) && empty($details['recipients'])) {
            // Cap at 10 — matches the pre-scan in actionIndex() which only
            // pre-fetched the first 10 ids per record to bound the batch size.
            $recipientIds = array_map('intval', array_slice($details['recipientIds'], 0, 10));
            $recipientIds = array_values(array_filter($recipientIds, static fn(int $id): bool => $id > 0));
            if ($recipientIds !== []) {
                $details['recipients'] = [];
                foreach ($recipientIds as $rid) {
                    if (!isset($recipientMap[$rid])) {
                        continue;
                    }
                    $recipient = $recipientMap[$rid];
                    $details['recipients'][] = [
                        'id' => $recipient->id,
                        'name' => $recipient->name,
                        'email' => $recipient->email,
                        'sms' => $recipient->sms,
                        'siteId' => $recipient->siteId,
                    ];
                }
            }
        }

        if (!empty($details['recipients']) && is_array($details['recipients'])) {
            foreach ($details['recipients'] as $index => $recipient) {
                if (!is_array($recipient)) {
                    continue;
                }

                if (!empty($recipient['siteId']) && empty($recipient['siteName'])) {
                    $siteId = (int)$recipient['siteId'];
                    if ($siteId > 0) {
                        $details['recipients'][$index]['siteName'] = Craft::$app->getSites()->getSiteById($siteId)?->name;
                    }
                }

                if (!empty($recipient['campaignId']) && empty($recipient['campaignName'])) {
                    $campaignId = (int)$recipient['campaignId'];
                    $details['recipients'][$index]['campaignName'] = $details['campaignNames'][$campaignId]
                        ?? ($campaignMap[$campaignId]->title ?? null);
                }
            }
        }

        return $details;
    }
}
