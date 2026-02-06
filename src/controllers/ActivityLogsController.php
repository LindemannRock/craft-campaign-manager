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
use craft\web\Controller;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\LoggingLibrary;
use yii\web\ForbiddenHttpException;
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
     * @since 5.4.0
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();
        if (!$user->checkPermission('campaignManager:viewActivityLogs')) {
            throw new ForbiddenHttpException('User does not have permission to view activity logs');
        }

        $settings = Craft::$app->getPlugins()->getPlugin('campaign-manager')->getSettings();
        $page = (int) Craft::$app->getRequest()->getParam('page', 1);
        $limit = $settings->itemsPerPage ?? 50;
        $offset = max(0, ($page - 1) * $limit);

        $query = ActivityLogRecord::find()->orderBy(['dateCreated' => SORT_DESC]);
        $totalCount = $query->count();

        $records = $query->offset($offset)->limit($limit)->all();
        $items = [];

        /** @var ActivityLogRecord $record */
        foreach ($records as $record) {
            $userName = $record->userId
                ? Craft::$app->getUsers()->getUserById($record->userId)?->username
                : null;

            $details = $record->details ? json_decode($record->details, true) : [];
            if (!is_array($details)) {
                $details = [];
            }
            $details = $this->enrichDetails($details);

            $campaignName = null;
            $campaignUrl = null;
            if ($record->campaignId) {
                $campaign = Campaign::find()
                    ->id($record->campaignId)
                    ->status(null)
                    ->one();
                if ($campaign) {
                    $campaignName = $campaign->title;
                    $campaignUrl = $campaign->getCpEditUrl();
                }
            }

            $recipientLabel = null;
            if ($record->recipientId) {
                $recipient = RecipientRecord::findOne($record->recipientId);
                if ($recipient) {
                    $recipientLabel = $recipient->name ?: ($recipient->email ?: $recipient->sms);
                }
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
     * @since 5.4.0
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser();
        if (!$user->checkPermission('campaignManager:clearActivityLogs')) {
            throw new ForbiddenHttpException('User does not have permission to clear activity logs');
        }

        ActivityLogRecord::deleteAll();

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Activity logs cleared successfully.'));

        return $this->asJson([
            'success' => true,
        ]);
    }

    /**
     * Format action key into human-friendly label
     *
     * @since 5.4.0
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
     * Enrich details with human-friendly context for display
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     * @since 5.4.0
     */
    private function enrichDetails(array $details): array
    {
        if (!empty($details['triggeredByUserId']) && empty($details['triggeredBy'])) {
            $triggeredByUserId = (int)$details['triggeredByUserId'];
            if ($triggeredByUserId > 0) {
                $details['triggeredBy'] = Craft::$app->getUsers()->getUserById($triggeredByUserId)?->username;
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
            $recipientIds = array_map('intval', $details['recipientIds']);
            $recipientIds = array_values(array_filter($recipientIds, static fn(int $id): bool => $id > 0));
            if ($recipientIds !== []) {
                $recipients = RecipientRecord::find()
                    ->where(['id' => $recipientIds])
                    ->limit(10)
                    ->all();

                $details['recipients'] = [];
                foreach ($recipients as $recipient) {
                    if (!$recipient instanceof RecipientRecord) {
                        continue;
                    }

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
                        ?? Campaign::find()->id($campaignId)->status(null)->one()?->title;
                }
            }
        }

        return $details;
    }
}
