<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\campaignmanager\services\AnalyticsService;

/**
 * Analytics service that remembers which sites each dashboard query asked for
 * before answering it as usual.
 *
 * @since 5.16.0
 */
final class RecordingAnalyticsService extends AnalyticsService
{
    /**
     * @var list<int|string|array<int>>
     */
    public array $requestedSites = [];

    public function getOverviewStats(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $this->requestedSites[] = $siteId;

        return parent::getOverviewStats($campaignId, $siteId, $dateRange);
    }

    public function getCampaignBreakdown(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $this->requestedSites[] = $siteId;

        return parent::getCampaignBreakdown($campaignId, $siteId, $dateRange);
    }
}
