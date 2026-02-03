<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Analytics Controller
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.1.0
 */
class AnalyticsController extends Controller
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * Analytics index page
     *
     * @return Response
     * @since 5.1.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('campaignManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $settings = CampaignManager::$plugin->getSettings();
        $analyticsService = CampaignManager::$plugin->analytics;

        // Get filter parameters
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $campaignId = $request->getQueryParam('campaign', 'all');
        $siteId = $request->getQueryParam('siteId', 'all');

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Normalize site ID - handle both ID and handle
        if ($siteId !== 'all' && $siteId !== '') {
            if (is_numeric($siteId)) {
                $siteId = (int)$siteId;
            } else {
                // It's a site handle, convert to ID
                $site = Craft::$app->getSites()->getSiteByHandle($siteId);
                $siteId = $site ? $site->id : 'all';
            }
        } else {
            $siteId = 'all';
        }

        // Get overview stats
        $summaryStats = $analyticsService->getOverviewStats($campaignId, $siteId, $dateRange);

        // Get campaign options for filter
        $campaignOptions = $analyticsService->getCampaignOptions($siteId);

        // Get sites for filter
        $sites = Craft::$app->getSites()->getAllSites();

        // Get campaign breakdown (filtered by selected campaign)
        $campaignBreakdown = $analyticsService->getCampaignBreakdown($campaignId, $siteId, $dateRange);

        return $this->renderTemplate('campaign-manager/analytics/index', [
            'settings' => $settings,
            'dateRange' => $dateRange,
            'campaignId' => $campaignId,
            'siteId' => $siteId,
            'summaryStats' => $summaryStats,
            'campaignOptions' => $campaignOptions,
            'campaignBreakdown' => $campaignBreakdown,
            'sites' => $sites,
            'pluginHandle' => CampaignManager::$plugin->id,
        ]);
    }

    /**
     * Get chart data via AJAX
     *
     * @return Response
     * @since 5.1.0
     */
    public function actionGetData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('campaignManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $type = $request->getBodyParam('type', 'daily');
        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $campaignId = $request->getBodyParam('campaignId', 'all');
        $siteId = $request->getBodyParam('siteId', 'all');

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Normalize site ID - handle both ID and handle
        if ($siteId !== 'all' && $siteId !== '') {
            if (is_numeric($siteId)) {
                $siteId = (int)$siteId;
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($siteId);
                $siteId = $site ? $site->id : 'all';
            }
        } else {
            $siteId = 'all';
        }

        $analyticsService = CampaignManager::$plugin->analytics;

        $data = match ($type) {
            'daily' => $analyticsService->getDailyTrend($campaignId, $siteId, $dateRange),
            'channels' => $analyticsService->getChannelDistribution($campaignId, $siteId, $dateRange),
            'engagement' => $analyticsService->getEngagementOverTime($campaignId, $siteId, $dateRange),
            'funnel' => $analyticsService->getConversionFunnel($campaignId, $siteId, $dateRange),
            default => [],
        };

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Export analytics data
     *
     * @return Response
     * @throws BadRequestHttpException
     * @since 5.1.0
     */
    public function actionExport(): Response
    {
        $this->requirePermission('campaignManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $format = $request->getQueryParam('format', 'csv');
        $campaignId = $request->getQueryParam('campaign', 'all');
        $siteId = $request->getQueryParam('siteId', 'all');

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, CampaignManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Normalize site ID - handle both ID and handle
        if ($siteId !== 'all' && $siteId !== '') {
            if (is_numeric($siteId)) {
                $siteId = (int)$siteId;
            } else {
                $site = Craft::$app->getSites()->getSiteByHandle($siteId);
                $siteId = $site ? $site->id : 'all';
            }
        } else {
            $siteId = 'all';
        }

        $analyticsService = CampaignManager::$plugin->analytics;

        // Get comprehensive stats for export
        $overviewStats = $analyticsService->getOverviewStats($campaignId, $siteId, $dateRange);
        $campaignBreakdown = $analyticsService->getCampaignBreakdown($campaignId, $siteId, $dateRange);

        // Check for empty data
        if (empty($campaignBreakdown) && $overviewStats['totalRecipients'] === 0) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No analytics data to export for the selected filters.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Build export rows with comprehensive data per campaign
        $rows = [];
        foreach ($campaignBreakdown as $data) {
            // Get detailed stats for this specific campaign
            $campaignStats = $analyticsService->getOverviewStats($data['campaignId'], $siteId, $dateRange);

            $rows[] = [
                'campaign' => $data['campaignName'],
                'totalRecipients' => $campaignStats['totalRecipients'],
                'emailsSent' => $campaignStats['emailsSent'],
                'smsSent' => $campaignStats['smsSent'],
                'emailsOpened' => $campaignStats['emailsOpened'],
                'smsOpened' => $campaignStats['smsOpened'],
                'emailOpenRate' => $campaignStats['emailOpenRate'] . '%',
                'smsOpenRate' => $campaignStats['smsOpenRate'] . '%',
                'submissions' => $campaignStats['submissions'],
                'conversionRate' => $campaignStats['conversionRate'] . '%',
                'expired' => $campaignStats['expired'],
            ];
        }

        $headers = [
            Craft::t('campaign-manager', 'Campaign'),
            Craft::t('campaign-manager', 'Total Recipients'),
            Craft::t('campaign-manager', 'Emails Sent'),
            Craft::t('campaign-manager', 'SMS Sent'),
            Craft::t('campaign-manager', 'Emails Opened'),
            Craft::t('campaign-manager', 'SMS Opened'),
            Craft::t('campaign-manager', 'Email Open Rate'),
            Craft::t('campaign-manager', 'SMS Open Rate'),
            Craft::t('campaign-manager', 'Submissions'),
            Craft::t('campaign-manager', 'Conversion Rate'),
            Craft::t('campaign-manager', 'Expired'),
        ];

        // Build filename
        $settings = CampaignManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = in_array($format, ['xlsx', 'excel'], true) ? 'xlsx' : $format;
        $filenameParts = ['analytics'];

        if (is_int($campaignId)) {
            $campaign = CampaignManager::$plugin->campaigns->getCampaignById($campaignId);
            if ($campaign) {
                $campaignSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($campaign->title ?? 'campaign'));
                $filenameParts[] = $campaignSlug;
            }
        }

        if (is_int($siteId)) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                $siteHandle = strtolower(preg_replace('/[^a-z0-9]+/', '-', $site->handle));
                $filenameParts[] = $siteHandle;
            }
        }

        $filenameParts[] = $dateRangeLabel;

        $filename = ExportHelper::filename($settings, $filenameParts, $extension);

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename),
            'json' => ExportHelper::toJson($rows, $filename),
            'xlsx', 'excel' => ExportHelper::toExcel($rows, $headers, $filename, [], [
                'sheetTitle' => 'Analytics',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }
}
