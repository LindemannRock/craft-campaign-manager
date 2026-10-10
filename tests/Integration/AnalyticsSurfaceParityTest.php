<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\base\WidgetInterface;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\AnalyticsController;
use lindemannrock\campaignmanager\controllers\CampaignsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController;
use lindemannrock\campaignmanager\tests\Support\SeedsAnalyticsEventMatrix;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\campaignmanager\widgets\CampaignPerformanceWidget;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The analytics page, its chart data, its export, the dashboard widgets and
 * a campaign's own analytics tab all describe one date range with the same
 * figures.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsController::class)]
#[CoversClass(AnalyticsService::class)]
#[CoversClass(AnalyticsSummaryWidget::class)]
#[CoversClass(CampaignPerformanceWidget::class)]
#[CoversClass(CampaignsController::class)]
final class AnalyticsSurfaceParityTest extends SiteRestrictedUserTestCase
{
    use SeedsAnalyticsEventMatrix;

    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:viewAnalytics',
        'campaignManager:exportAnalytics',
    ];

    public function testThePageItsChartsAndItsExportAgree(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $campaignId = (int)$matrix['campaign']->id;
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['campaign' => (string)$campaignId, 'dateRange' => self::MATRIX_RANGE, 'siteId' => 'all']);
        $variables = $this->analyticsController()->actionIndex()->data['variables'];
        $summary = $variables['summaryStats'];
        self::assertSame(self::MATRIX_TOTALS, array_intersect_key($summary, self::MATRIX_TOTALS));

        $breakdown = $variables['campaignBreakdown'];
        self::assertCount(1, $breakdown);
        foreach (['totalRecipients', 'emailsSent', 'smsSent', 'emailsOpened', 'smsOpened', 'submissions', 'expired'] as $key) {
            self::assertSame($summary[$key], $breakdown[0][$key], "The campaign performance table must agree with the summary on {$key}.");
        }

        $funnel = $this->chartData('funnel', $campaignId);
        self::assertSame([$summary['totalRecipients'], $summary['uniqueInvited'], $summary['uniqueOpened'], $summary['submissions']], $funnel['values']);

        $daily = $this->chartData('daily', $campaignId);
        self::assertSame($summary['totalRecipients'], array_sum($daily['recipients']));
        self::assertSame($summary['emailsSent'], array_sum($daily['emailsSent']));
        self::assertSame($summary['smsSent'], array_sum($daily['smsSent']));
        self::assertSame($summary['submissions'], array_sum($daily['submissions']));

        $engagement = $this->chartData('engagement', $campaignId);
        self::assertSame($summary['emailsOpened'], array_sum($engagement['emailOpens']));
        self::assertSame($summary['smsOpened'], array_sum($engagement['smsOpens']));

        $channels = $this->chartData('channels', $campaignId);
        self::assertSame($summary['uniqueInvited'], array_sum($channels['values']));

        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaignId, 'dateRange' => self::MATRIX_RANGE, 'siteId' => 'all']);
        $csv = (string)$this->analyticsController()->actionExport()->content;
        self::assertStringContainsString((string)$matrix['campaign']->title, $csv);
        self::assertStringContainsString(
            implode(',', [
                $summary['totalRecipients'],
                $summary['emailsSent'],
                $summary['smsSent'],
                $summary['emailsOpened'],
                $summary['smsOpened'],
                $summary['emailOpenRate'] . '%',
                $summary['smsOpenRate'] . '%',
                $summary['submissions'],
                $summary['conversionRate'] . '%',
                $summary['expired'],
            ]),
            $csv,
        );
    }

    public function testTheDashboardWidgetsAgreeWithThePage(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $summary = CampaignManager::$plugin->analytics->getOverviewStats('all', [$siteA], self::MATRIX_RANGE);

        $summaryHtml = (string)$this->saveWidget(AnalyticsSummaryWidget::class, $siteA)->getBodyHtml();
        self::assertSame($summary['totalRecipients'], $this->statValue($summaryHtml, 'Total Recipients'));
        self::assertSame($summary['totalSent'], $this->statValue($summaryHtml, 'Total Sent'));
        // The widget shows rates as whole numbers.
        self::assertSame((int)round($summary['openRate']), $this->statValue($summaryHtml, 'Open Rate'));
        self::assertSame((int)round($summary['conversionRate']), $this->statValue($summaryHtml, 'Conversion Rate'));

        $performanceHtml = (string)$this->saveWidget(CampaignPerformanceWidget::class, $siteA)->getBodyHtml();
        self::assertStringContainsString((string)$matrix['campaign']->title, $performanceHtml);
        $text = (string)preg_replace('/\s+/', ' ', strip_tags($performanceHtml));
        self::assertStringContainsString(self::MATRIX_TOTALS['totalRecipients'] . ' Total Recipients · ' . self::MATRIX_TOTALS['submissions'] . ' Submissions', $text);
    }

    public function testACampaignsOwnAnalyticsTabAgreesWithThePage(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $html = $this->renderAnalyticsTab($matrix['campaign'], $siteA, self::MATRIX_RANGE);

        self::assertSame(self::MATRIX_TOTALS['totalRecipients'], $this->statValue($html, 'Recipients'));
        self::assertSame(self::MATRIX_TOTALS['totalSent'], $this->statValue($html, 'Sent'));
        self::assertSame(self::MATRIX_TOTALS['totalOpened'], $this->statValue($html, 'Opened'));
        self::assertSame(self::MATRIX_TOTALS['submissions'], $this->statValue($html, 'Responses'));
        self::assertStringContainsString('campaign-daily-chart', $html);
    }

    public function testACampaignsAnalyticsTabChartsActivityEvenWhenNoRecipientWasAddedInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $bounds = DateRangeHelper::getBounds(self::MATRIX_RANGE);
        self::assertNotNull($bounds['start']);
        $this->seedOwnedRecipient($campaign, $siteA, [
            'dateCreated' => Db::prepareDateForDb((clone $bounds['start'])->modify('-10 days')),
            'emailSendDate' => Db::prepareDateForDb((clone $bounds['start'])->modify('+2 days')),
        ]);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $html = $this->renderAnalyticsTab($campaign, $siteA, self::MATRIX_RANGE);

        self::assertSame(0, $this->statValue($html, 'Recipients'));
        self::assertSame(1, $this->statValue($html, 'Sent'));
        self::assertStringContainsString('campaign-daily-chart', $html, 'A range with activity shows its charts even when no recipient was added in it.');
        self::assertStringNotContainsString('No data available yet.', $html);
    }

    public function testACampaignsAnalyticsTabShowsTheEmptyStateWithoutAnyActivity(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteA, [
            'dateCreated' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-2 years')),
        ]);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $html = $this->renderAnalyticsTab($campaign, $siteA, self::MATRIX_RANGE);

        self::assertStringNotContainsString('campaign-daily-chart', $html);
        self::assertStringContainsString('No data available yet.', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function chartData(string $type, int $campaignId): array
    {
        $this->request('POST', [], ['type' => $type, 'campaignId' => (string)$campaignId, 'dateRange' => self::MATRIX_RANGE, 'siteId' => 'all']);
        $payload = $this->analyticsController()->actionGetData()->data;
        self::assertTrue($payload['success']);

        return $payload['data'];
    }

    private function renderAnalyticsTab(Campaign $campaign, int $siteId, string $dateRange): string
    {
        $this->request('GET', [
            'campaignId' => (string)$campaign->id,
            'tab' => 'analytics',
            'site' => $this->siteHandle($siteId),
            'dateRange' => $dateRange,
        ]);

        return (string)$this->campaignsController()->actionRenderTab()->data['html'];
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    private function saveWidget(string $type, int $siteId): WidgetInterface
    {
        $widget = Craft::$app->getDashboard()->createWidget([
            'type' => $type,
            'settings' => ['siteId' => (string)$siteId, 'dateRange' => self::MATRIX_RANGE],
        ]);
        self::assertTrue(Craft::$app->getDashboard()->saveWidget($widget), 'The widget settings must be accepted.');

        return $widget;
    }

    /**
     * The number rendered right before a stat label.
     */
    private function statValue(string $html, string $label): int
    {
        $text = (string)preg_replace('/\s+/', ' ', strip_tags($html));
        if (preg_match('/(\d[\d,]*)%?\s+' . preg_quote($label, '/') . '\b/', $text, $matches) !== 1) {
            self::fail("The {$label} figure was not rendered.");
        }

        return (int)str_replace(',', '', $matches[1]);
    }

    private function analyticsController(): CapturingAnalyticsController
    {
        return new CapturingAnalyticsController('analytics', CampaignManager::$plugin);
    }
}
