<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\AnalyticsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\widgets\CampaignPerformanceWidget;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A campaign that has been disabled keeps its history: analytics breakdowns,
 * campaign filters, exports and the performance widget still report it, while
 * the element queries that drive sending keep leaving it out.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
#[CoversClass(AnalyticsController::class)]
#[CoversClass(CampaignPerformanceWidget::class)]
final class AnalyticsDisabledCampaignHistoryTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:viewAnalytics',
        'campaignManager:exportAnalytics',
    ];

    public function testADisabledCampaignKeepsItsRowInTheBreakdownAndOptions(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDisabledCampaignWithHistory($siteA, 3);
        $analytics = CampaignManager::$plugin->analytics;

        $rows = $analytics->getCampaignBreakdown('all', [$siteA], 'all');
        $row = $this->rowFor($rows, (int)$campaign->id);
        self::assertNotNull($row, 'The disabled campaign must stay in the historical breakdown.');
        self::assertSame(3, $row['totalRecipients']);
        self::assertSame(3, $row['emailsSent']);

        $single = $analytics->getCampaignBreakdown((int)$campaign->id, [$siteA], 'all');
        self::assertCount(1, $single, 'Selecting the disabled campaign alone still reports it.');
        self::assertSame(3, $single[0]['totalRecipients']);

        self::assertContains($campaign->id, array_column($analytics->getCampaignOptions([$siteA]), 'value'));
    }

    public function testADisabledCampaignStaysOnTheAnalyticsPageAndInItsExport(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDisabledCampaignWithHistory($siteA, 2);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['campaign' => 'all', 'dateRange' => 'all', 'siteId' => 'all']);
        $variables = $this->analyticsController()->actionIndex()->data['variables'];
        self::assertNotNull($this->rowFor($variables['campaignBreakdown'], (int)$campaign->id));
        self::assertContains($campaign->id, array_column($variables['campaignOptions'], 'value'));

        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all']);
        $csv = (string)$this->analyticsController()->actionExport()->content;
        self::assertStringContainsString((string)$campaign->title, $csv);
    }

    public function testADisabledCampaignStaysInThePerformanceWidget(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDisabledCampaignWithHistory($siteA, 2);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $widget = \Craft::$app->getDashboard()->createWidget([
            'type' => CampaignPerformanceWidget::class,
            'settings' => ['siteId' => (string)$siteA, 'dateRange' => 'all', 'limit' => 20],
        ]);
        self::assertTrue(\Craft::$app->getDashboard()->saveWidget($widget));

        self::assertStringContainsString((string)$campaign->title, (string)$widget->getBodyHtml());
    }

    public function testADisabledCampaignIsStillLeftOutOfEnabledCampaignQueries(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedDisabledCampaignWithHistory($siteA, 1);

        // The default element status is what sending and import paths rely on.
        self::assertFalse(Campaign::find()->id($campaign->id)->siteId($siteA)->exists());
        self::assertFalse(CampaignManager::$plugin->campaigns->find()->id($campaign->id)->siteId($siteA)->exists());
        self::assertTrue(Campaign::find()->id($campaign->id)->siteId($siteA)->status(null)->exists());

        $reloaded = Campaign::find()->id($campaign->id)->siteId($siteA)->status(null)->one();
        self::assertInstanceOf(Campaign::class, $reloaded);
        self::assertFalse((bool)$reloaded->getEnabledForSite($siteA));
    }

    private function seedDisabledCampaignWithHistory(int $siteId, int $recipients): Campaign
    {
        $campaign = $this->seedOwnedCampaign();
        $sentAt = Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-3 days'));
        for ($i = 0; $i < $recipients; $i++) {
            $this->seedOwnedRecipient($campaign, $siteId, ['emailSendDate' => $sentAt]);
        }

        $this->disableOwnedCampaignForSite($campaign, $siteId);

        return $campaign;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, int $campaignId): ?array
    {
        foreach ($rows as $row) {
            if ((int)$row['campaignId'] === $campaignId) {
                return $row;
            }
        }

        return null;
    }

    private function analyticsController(): CapturingAnalyticsController
    {
        return new CapturingAnalyticsController('analytics', CampaignManager::$plugin);
    }
}
