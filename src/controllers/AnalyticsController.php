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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
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
        $rawSiteId = $request->getQueryParam('siteId', 'all');
        $dateBasis = $request->getQueryParam('dateBasis', 'sent');

        // Validate dateBasis
        if (!in_array($dateBasis, ['sent', 'response'], true)) {
            $dateBasis = 'sent';
        }

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Resolve and validate site ID against editable sites
        $effectiveSiteId = $this->_resolveSiteId($rawSiteId);

        // Get overview stats
        $summaryStats = $analyticsService->getOverviewStats($campaignId, $effectiveSiteId, $dateRange);

        // Get campaign options for filter
        $campaignOptions = $analyticsService->getCampaignOptions($effectiveSiteId);

        // Get sites for filter (only editable)
        $sites = Craft::$app->getSites()->getEditableSites();

        // Get campaign breakdown (filtered by selected campaign)
        $campaignBreakdown = $analyticsService->getCampaignBreakdown($campaignId, $effectiveSiteId, $dateRange);

        // Pass display-friendly siteId (string/int) for template filters/JS, not the array
        $displaySiteId = is_array($effectiveSiteId) ? 'all' : $effectiveSiteId;

        // NPS tab data (only when formie-rating-field is enabled)
        $ratingFields = [];
        $ratingFieldId = null;
        $npsStats = [];
        $campaignNpsBreakdown = [];

        if (PluginHelper::isPluginEnabled('formie-rating-field')) {
            $rawRating = $request->getQueryParam('rating');
            $ratingFields = $analyticsService->getRatingFieldsInScope($campaignId, $effectiveSiteId, $dateRange, $dateBasis);

            if ($rawRating !== null && is_numeric($rawRating)) {
                $ratingFieldId = (int)$rawRating;
            } elseif (!empty($ratingFields)) {
                $ratingFieldId = $ratingFields[0]['fieldId'];
            }

            $npsStats = $analyticsService->getNpsStats($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $ratingFieldId);
            $campaignNpsBreakdown = $analyticsService->getCampaignNpsBreakdown($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $ratingFieldId);
        }

        return $this->renderTemplate('campaign-manager/analytics/index', [
            'settings' => $settings,
            'dateRange' => $dateRange,
            'campaignId' => $campaignId,
            'siteId' => $displaySiteId,
            'dateBasis' => $dateBasis,
            'summaryStats' => $summaryStats,
            'campaignOptions' => $campaignOptions,
            'campaignBreakdown' => $campaignBreakdown,
            'sites' => $sites,
            'pluginHandle' => CampaignManager::$plugin->id,
            'ratingFields' => $ratingFields,
            'ratingFieldId' => $ratingFieldId,
            'npsStats' => $npsStats,
            'campaignNpsBreakdown' => $campaignNpsBreakdown,
        ]);
    }

    /**
     * Get chart data via AJAX
     *
     * @return Response
     */
    public function actionGetData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('campaignManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $type = $request->getBodyParam('type', 'daily');

        $validTypes = ['daily', 'channels', 'engagement', 'funnel', 'nps-distribution', 'nps-trend'];
        if (!in_array($type, $validTypes, true)) {
            throw new BadRequestHttpException('Invalid data type.');
        }

        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $campaignId = $request->getBodyParam('campaignId', 'all');
        $rawSiteId = $request->getBodyParam('siteId', 'all');
        $dateBasis = $request->getBodyParam('dateBasis', 'sent');

        // Validate dateBasis
        if (!in_array($dateBasis, ['sent', 'response'], true)) {
            $dateBasis = 'sent';
        }

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Resolve and validate site ID against editable sites
        $effectiveSiteId = $this->_resolveSiteId($rawSiteId);

        // NPS field ID (optional, for nps-* types)
        $rawFieldId = $request->getBodyParam('fieldId');
        $fieldId = ($rawFieldId !== null && is_numeric($rawFieldId)) ? (int)$rawFieldId : null;

        $analyticsService = CampaignManager::$plugin->analytics;

        $data = match ($type) {
            'daily' => $analyticsService->getDailyTrend($campaignId, $effectiveSiteId, $dateRange),
            'channels' => $analyticsService->getChannelDistribution($campaignId, $effectiveSiteId, $dateRange),
            'engagement' => $analyticsService->getEngagementOverTime($campaignId, $effectiveSiteId, $dateRange),
            'funnel' => $analyticsService->getConversionFunnel($campaignId, $effectiveSiteId, $dateRange),
            'nps-distribution' => $analyticsService->getNpsDistribution($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId),
            'nps-trend' => $analyticsService->getNpsTrend($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId),
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
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('campaignManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $format = $request->getBodyParam('format', 'csv');
        $campaignId = $request->getBodyParam('campaign', 'all');
        $rawSiteId = $request->getBodyParam('siteId', 'all');

        // Read and validate export scope
        $scope = $request->getBodyParam('scope', 'campaigns');
        if (!in_array($scope, ['campaigns', 'nps'], true)) {
            $scope = 'campaigns';
        }

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, CampaignManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        // Normalize campaign ID
        if ($campaignId !== 'all' && is_numeric($campaignId)) {
            $campaignId = (int)$campaignId;
        }

        // Resolve and validate site ID against editable sites
        $effectiveSiteId = $this->_resolveSiteId($rawSiteId);

        $analyticsService = CampaignManager::$plugin->analytics;
        $settings = CampaignManager::$plugin->getSettings();
        $extension = in_array($format, ['xlsx', 'excel'], true) ? 'xlsx' : $format;
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;

        if ($scope === 'nps') {
            return $this->_exportNps($request, $analyticsService, $settings, $campaignId, $effectiveSiteId, $dateRange, $dateRangeLabel, $format, $extension);
        }

        // Get comprehensive stats for export
        $overviewStats = $analyticsService->getOverviewStats($campaignId, $effectiveSiteId, $dateRange);
        $campaignBreakdown = $analyticsService->getCampaignBreakdown($campaignId, $effectiveSiteId, $dateRange);

        // Check for empty data
        if (empty($campaignBreakdown) && $overviewStats['totalRecipients'] === 0) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No analytics data to export for the selected filters.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Build export rows with comprehensive data per campaign
        $rows = [];
        foreach ($campaignBreakdown as $data) {
            // Get detailed stats for this specific campaign
            $campaignStats = $analyticsService->getOverviewStats($data['campaignId'], $data['siteId'], $dateRange);

            $site = $data['siteId'] ? Craft::$app->getSites()->getSiteById($data['siteId']) : null;

            $rows[] = [
                'campaign' => $data['campaignName'],
                'site' => $site?->name ?? '—',
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
            Craft::t('campaign-manager', 'Site'),
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
        $filenameParts = ['analytics'];

        if (is_int($campaignId)) {
            $campaign = CampaignManager::$plugin->campaigns->getCampaignById($campaignId);
            if ($campaign) {
                $campaignSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($campaign->title ?? 'campaign'));
                $filenameParts[] = $campaignSlug;
            }
        }

        if (is_int($effectiveSiteId)) {
            $site = Craft::$app->getSites()->getSiteById($effectiveSiteId);
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

    /**
     * Handle NPS-scoped export branch.
     *
     * @param \craft\web\Request $request
     * @param \lindemannrock\campaignmanager\services\AnalyticsService $analyticsService
     * @param object $settings
     * @param int|string $campaignId
     * @param int|array<int> $effectiveSiteId
     * @param string $dateRange
     * @param string $dateRangeLabel
     * @param string $format
     * @param string $extension
     * @return Response
     * @throws BadRequestHttpException
     */
    private function _exportNps(
        \craft\web\Request $request,
        \lindemannrock\campaignmanager\services\AnalyticsService $analyticsService,
        object $settings,
        int|string $campaignId,
        int|array $effectiveSiteId,
        string $dateRange,
        string $dateRangeLabel,
        string $format,
        string $extension,
    ): Response {
        // Read NPS-specific params
        $rawFieldId = $request->getBodyParam('fieldId');
        $fieldId = ($rawFieldId !== null && is_numeric($rawFieldId) && (int)$rawFieldId > 0) ? (int)$rawFieldId : null;

        $dateBasis = $request->getBodyParam('dateBasis', 'sent');
        if (!in_array($dateBasis, ['sent', 'response'], true)) {
            $dateBasis = 'sent';
        }

        // Guard: plugin not enabled or no field selected
        if (!PluginHelper::isPluginEnabled('formie-rating-field') || $fieldId === null) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No NPS data available to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Resolve rating field metadata
        $ratingFields = $analyticsService->getRatingFieldsInScope($campaignId, $effectiveSiteId, $dateRange, $dateBasis);
        $ratingFieldInfo = null;
        foreach ($ratingFields as $rf) {
            if ($rf['fieldId'] === $fieldId) {
                $ratingFieldInfo = $rf;
                break;
            }
        }

        if ($ratingFieldInfo === null) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No NPS data available to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Fetch data
        $campaignNpsBreakdown = $analyticsService->getCampaignNpsBreakdown($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId);

        if (empty($campaignNpsBreakdown)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No NPS data available to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        $dateBasisLabel = $dateBasis === 'response'
            ? Craft::t('campaign-manager', 'Response date')
            : Craft::t('campaign-manager', 'Send activity');

        $ratingFieldLabel = $ratingFieldInfo['label'];

        // Build rows
        $rows = [];
        foreach ($campaignNpsBreakdown as $data) {
            $site = $data['siteId'] ? Craft::$app->getSites()->getSiteById($data['siteId']) : null;
            $hasResponses = $data['totalResponses'] > 0;

            $rows[] = [
                'campaign' => $data['campaignName'],
                'site' => $site?->name ?? '—',
                'ratingField' => $ratingFieldLabel,
                'dateBasis' => $dateBasisLabel,
                'responses' => $data['totalResponses'],
                'npsScore' => $hasResponses ? ($data['npsScore'] !== null ? $data['npsScore'] : '—') : '—',
                'promoters' => $hasResponses ? $data['promoters'] : '—',
                'passives' => $hasResponses ? $data['passives'] : '—',
                'detractors' => $hasResponses ? $data['detractors'] : '—',
            ];
        }

        $headers = [
            Craft::t('campaign-manager', 'Campaign'),
            Craft::t('campaign-manager', 'Site'),
            Craft::t('campaign-manager', 'Rating Field'),
            Craft::t('campaign-manager', 'Date Basis'),
            Craft::t('campaign-manager', 'Responses'),
            Craft::t('campaign-manager', 'NPS Score'),
            Craft::t('campaign-manager', 'Promoters'),
            Craft::t('campaign-manager', 'Passives'),
            Craft::t('campaign-manager', 'Detractors'),
        ];

        // Build filename: campaign-nps-[campaignSlug?]-[siteHandle?]-{fieldHandle}-{dateRange}-{dateBasis}-{timestamp}.csv
        $filenameParts = ['nps'];

        if (is_int($campaignId)) {
            $campaign = CampaignManager::$plugin->campaigns->getCampaignById($campaignId);
            if ($campaign) {
                $campaignSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($campaign->title ?? 'campaign'));
                $filenameParts[] = $campaignSlug;
            }
        }

        if (is_int($effectiveSiteId)) {
            $site = Craft::$app->getSites()->getSiteById($effectiveSiteId);
            if ($site) {
                $siteHandle = strtolower(preg_replace('/[^a-z0-9]+/', '-', $site->handle));
                $filenameParts[] = $siteHandle;
            }
        }

        $fieldHandleSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($ratingFieldInfo['handle']));
        $filenameParts[] = $fieldHandleSlug;
        $filenameParts[] = $dateRangeLabel;
        $filenameParts[] = $dateBasis;

        $filename = ExportHelper::filename($settings, $filenameParts, $extension);

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename),
            'json' => ExportHelper::toJson($rows, $filename),
            'xlsx', 'excel' => ExportHelper::toExcel($rows, $headers, $filename, [], [
                'sheetTitle' => 'NPS',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }

    /**
     * Resolve and validate the site ID parameter against editable sites.
     *
     * @param string|null $rawSiteId Raw site ID from request ('all', numeric ID, site handle, or null)
     * @return int|array<int> Validated site ID or array of editable site IDs
     * @throws ForbiddenHttpException if specific site is not editable
     */
    private function _resolveSiteId(?string $rawSiteId): int|array
    {
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();

        if ($rawSiteId === null || $rawSiteId === '' || $rawSiteId === 'all') {
            return $editableSiteIds;
        }

        // Convert handle to ID if needed
        if (is_numeric($rawSiteId)) {
            $siteId = (int)$rawSiteId;
        } else {
            $site = Craft::$app->getSites()->getSiteByHandle($rawSiteId);
            $siteId = $site ? $site->id : null;
        }

        if ($siteId === null || !in_array($siteId, $editableSiteIds, true)) {
            throw new ForbiddenHttpException('You do not have permission to view analytics for this site.');
        }

        return $siteId;
    }
}
