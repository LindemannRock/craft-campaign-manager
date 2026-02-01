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
use lindemannrock\base\helpers\DateTimeHelper;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
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

            $items[] = [
                'date' => DateTimeHelper::formatDatetime($record->dateCreated),
                'user' => $userName ?? Craft::t('campaign-manager', 'System'),
                'action' => $record->action,
                'summary' => $record->summary ?? '-',
                'source' => $record->source,
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
}
