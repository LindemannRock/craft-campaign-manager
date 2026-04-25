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

        // Ratings tab data (only when formie-rating-field is enabled)
        $ratingFields = [];
        $ratingFieldId = null;
        $ratingStats = [];
        $campaignRatingBreakdown = ['fieldType' => null, 'rows' => []];
        $ratingFieldType = null;

        if (PluginHelper::isPluginEnabled('formie-rating-field')) {
            $rawRating = $request->getQueryParam('rating');
            $ratingFields = $analyticsService->getRatingFieldsInScope($campaignId, $effectiveSiteId, $dateRange, $dateBasis);

            if ($rawRating !== null && is_numeric($rawRating)) {
                $ratingFieldId = (int)$rawRating;
            } elseif (!empty($ratingFields)) {
                $ratingFieldId = $ratingFields[0]['fieldId'];
            }

            $ratingStats = $analyticsService->getRatingStats($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $ratingFieldId);
            $campaignRatingBreakdown = $analyticsService->getCampaignRatingBreakdown($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $ratingFieldId);
            $ratingFieldType = $ratingStats['fieldType'] ?? null;
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
            'ratingStats' => $ratingStats,
            'campaignRatingBreakdown' => $campaignRatingBreakdown,
            'ratingFieldType' => $ratingFieldType,
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

        $validTypes = ['daily', 'channels', 'engagement', 'funnel'];
        if (PluginHelper::isPluginEnabled('formie-rating-field')) {
            $validTypes[] = 'rating-distribution';
            $validTypes[] = 'rating-trend';
        }
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

        // Rating field ID (optional, for rating-* types)
        $rawFieldId = $request->getBodyParam('fieldId');
        $fieldId = ($rawFieldId !== null && is_numeric($rawFieldId)) ? (int)$rawFieldId : null;

        $analyticsService = CampaignManager::$plugin->analytics;

        $data = match ($type) {
            'daily' => $analyticsService->getDailyTrend($campaignId, $effectiveSiteId, $dateRange),
            'channels' => $analyticsService->getChannelDistribution($campaignId, $effectiveSiteId, $dateRange),
            'engagement' => $analyticsService->getEngagementOverTime($campaignId, $effectiveSiteId, $dateRange),
            'funnel' => $analyticsService->getConversionFunnel($campaignId, $effectiveSiteId, $dateRange),
            'rating-distribution' => $analyticsService->getRatingDistribution($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId),
            'rating-trend' => $analyticsService->getRatingTrend($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId),
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
        if (!in_array($scope, ['campaigns', 'ratings'], true)) {
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

        if ($scope === 'ratings') {
            return $this->_exportRatings($request, $analyticsService, $settings, $campaignId, $effectiveSiteId, $dateRange, $dateRangeLabel, $format, $extension);
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
     * Handle ratings-scoped export branch.
     *
     * Columns vary by field type:
     * - NPS:        Campaign, Site, Rating Field, Date Basis, Responses, NPS Score, Promoters, Passives, Detractors
     * - Star/Emoji: Campaign, Site, Rating Field, Date Basis, Responses, Average, Median, Most Common, Min, Max
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
    private function _exportRatings(
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
        // Read ratings-specific params
        $rawFieldId = $request->getBodyParam('fieldId');
        $fieldId = ($rawFieldId !== null && is_numeric($rawFieldId) && (int)$rawFieldId > 0) ? (int)$rawFieldId : null;

        $dateBasis = $request->getBodyParam('dateBasis', 'sent');
        if (!in_array($dateBasis, ['sent', 'response'], true)) {
            $dateBasis = 'sent';
        }

        // Guard: plugin not enabled or no field selected
        if (!PluginHelper::isPluginEnabled('formie-rating-field') || $fieldId === null) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No ratings data available to export.'));
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
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No ratings data available to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Fetch data — new shape: {fieldType, rows}
        $breakdownResult = $analyticsService->getCampaignRatingBreakdown($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId);
        $fieldType = $breakdownResult['fieldType'];
        $breakdownRows = $breakdownResult['rows'];

        if (empty($breakdownRows)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No ratings data available to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Fetch overall stats for min/max on Star/Emoji exports
        $overallStats = $analyticsService->getRatingStats($campaignId, $effectiveSiteId, $dateRange, $dateBasis, $fieldId);

        $dateBasisLabel = $dateBasis === 'response'
            ? Craft::t('campaign-manager', 'Response date')
            : Craft::t('campaign-manager', 'Send activity');

        $ratingFieldLabel = $ratingFieldInfo['label'];

        // Determine if this is an NPS field
        $isNps = $fieldType === \lindemannrock\formieratingfield\fields\Rating::RATING_TYPE_NPS;

        // Build rows
        $rows = [];
        foreach ($breakdownRows as $data) {
            $site = $data['siteId'] ? Craft::$app->getSites()->getSiteById($data['siteId']) : null;
            $hasResponses = $data['totalResponses'] > 0;

            if ($isNps) {
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
            } else {
                $rows[] = [
                    'campaign' => $data['campaignName'],
                    'site' => $site?->name ?? '—',
                    'ratingField' => $ratingFieldLabel,
                    'dateBasis' => $dateBasisLabel,
                    'responses' => $data['totalResponses'],
                    'average' => $hasResponses ? round((float)($data['average'] ?? 0), 2) : '—',
                    'median' => $hasResponses ? $data['median'] : '—',
                    'mode' => $hasResponses ? ($data['mode'] ?? '—') : '—',
                    'min' => $overallStats['minValue'] ?? '—',
                    'max' => $overallStats['maxValue'] ?? '—',
                ];
            }
        }

        // Build headers by type
        if ($isNps) {
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
        } else {
            $headers = [
                Craft::t('campaign-manager', 'Campaign'),
                Craft::t('campaign-manager', 'Site'),
                Craft::t('campaign-manager', 'Rating Field'),
                Craft::t('campaign-manager', 'Date Basis'),
                Craft::t('campaign-manager', 'Responses'),
                Craft::t('campaign-manager', 'Average'),
                Craft::t('campaign-manager', 'Median'),
                Craft::t('campaign-manager', 'Most Common'),
                Craft::t('campaign-manager', 'Min'),
                Craft::t('campaign-manager', 'Max'),
            ];
        }

        // Build filename: ratings-[campaignSlug?]-[siteHandle?]-{fieldHandle}-{dateRange}-{dateBasis}-{timestamp}
        $filenameParts = ['ratings'];

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
                'sheetTitle' => 'Ratings',
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
