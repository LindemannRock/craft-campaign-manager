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
use craft\db\Query;
use craft\elements\User;
use lindemannrock\campaignmanager\tests\Stubs\RecordingAnalyticsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\campaignmanager\widgets\CampaignPerformanceWidget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Dashboard widgets check their stored site against the sites the user may
 * edit each time they render.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsSummaryWidget::class)]
#[CoversClass(CampaignPerformanceWidget::class)]
final class DashboardWidgetEditableSiteScopeTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:viewAnalytics',
    ];

    private RecordingAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = new RecordingAnalyticsService();
        $this->swapPluginComponent('campaign-manager', 'analytics', $this->analytics);
    }

    /**
     * @return array<string, array{class-string<WidgetInterface>}>
     */
    public static function widgetTypes(): array
    {
        return [
            'analytics summary' => [AnalyticsSummaryWidget::class],
            'campaign performance' => [CampaignPerformanceWidget::class],
        ];
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    #[DataProvider('widgetTypes')]
    public function testWidgetRendersItsStoredSiteWhileTheUserMayEditIt(string $type): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);

        $widget = $this->saveWidget($type, (string)$siteB);
        $widget->getBodyHtml();

        self::assertSame([$siteB], $this->analytics->requestedSites);
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    #[DataProvider('widgetTypes')]
    public function testWidgetStopsShowingASiteAfterAccessToItIsRevoked(string $type): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $user = $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);
        $widget = $this->saveWidget($type, (string)$siteB);
        $storedSettings = $this->storedSettings($widget);

        $this->allowSites($user, [$siteA], self::PERMISSIONS);
        $reloaded = $this->reloadWidget($widget, $user);
        $html = (string)$reloaded->getBodyHtml();

        self::assertSame([], $this->analytics->requestedSites);
        self::assertStringContainsString('No data available', $html);
        self::assertSame($storedSettings, $this->storedSettings($widget));
        self::assertSame((string)$siteB, json_decode($storedSettings, true)['siteId'] ?? null);
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    #[DataProvider('widgetTypes')]
    public function testWidgetForAllSitesCoversOnlyEditableSites(string $type): void
    {
        [$siteA, $siteB] = $this->siteIds(3);
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);

        $widget = $this->saveWidget($type, 'all');
        $widget->getBodyHtml();

        self::assertSame([[$siteA, $siteB]], $this->analytics->requestedSites);
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    #[DataProvider('widgetTypes')]
    public function testWidgetShowsNoDataToAUserWithoutAnEditableSite(string $type): void
    {
        $this->siteIds(2);
        $this->actAsUserRestrictedTo([], self::PERMISSIONS);

        $html = (string)$this->saveWidget($type, 'all')->getBodyHtml();

        self::assertStringContainsString('No data available', $html);
        self::assertSame([], $this->analytics->requestedSites, 'No analytics query may run for a user without an editable site.');
    }

    /**
     * @param class-string<WidgetInterface> $type
     */
    private function saveWidget(string $type, string $siteId): WidgetInterface
    {
        $widget = Craft::$app->getDashboard()->createWidget([
            'type' => $type,
            'settings' => ['siteId' => $siteId, 'dateRange' => 'last7days'],
        ]);

        self::assertTrue(Craft::$app->getDashboard()->saveWidget($widget), 'The widget settings must be accepted.');
        self::assertNotNull($widget->id);

        return $widget;
    }

    private function reloadWidget(WidgetInterface $widget, User $user): WidgetInterface
    {
        $reloaded = Craft::$app->getDashboard()->getWidgetById((int)$widget->id);
        self::assertInstanceOf($widget::class, $reloaded);
        self::assertSame((int)$user->id, (int)Craft::$app->getUser()->getId());

        return $reloaded;
    }

    private function storedSettings(WidgetInterface $widget): string
    {
        return (string)(new Query())
            ->select('settings')
            ->from('{{%widgets}}')
            ->where(['id' => $widget->id])
            ->scalar();
    }
}
