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
use craft\errors\MissingComponentException;
use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\AnalyticsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The analytics page, its chart data and its export show a control panel user
 * only the sites that user may edit. A user without an editable site sees no
 * campaign at all, even on the site the request happens to run on.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsController::class)]
#[CoversClass(AnalyticsService::class)]
final class AnalyticsPageEditableSiteScopeTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:viewAnalytics',
        'campaignManager:exportAnalytics',
    ];

    public function testAUserWithoutAnEditableSiteSeesNoCampaignOnTheAnalyticsPage(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 2, $siteB => 3]);
        $currentSiteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        self::assertTrue(
            Campaign::find()->id($campaign->id)->siteId($currentSiteId)->status(null)->exists(),
            'The campaign must exist on the current site for a current-site fallback to show.',
        );

        $this->actAsUserRestrictedTo([], self::PERMISSIONS);
        $variables = $this->indexVariables($campaign);

        self::assertSame(0, $variables['summaryStats']['totalRecipients']);
        self::assertSame(0, $variables['summaryStats']['totalSent']);
        self::assertSame([], $variables['campaignBreakdown']);
        self::assertSame([], $variables['sites']);
        self::assertSame(['all'], array_column($variables['campaignOptions'], 'value'));
        self::assertStringNotContainsString((string)$campaign->title, (string)json_encode($variables));
    }

    public function testAUserWithoutAnEditableSiteGetsEmptyChartData(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 2, $siteB => 3]);

        $this->actAsUserRestrictedTo([], self::PERMISSIONS);

        foreach (['daily', 'channels', 'engagement', 'funnel'] as $type) {
            $this->request('POST', [], ['type' => $type, 'campaignId' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all']);
            $payload = $this->analyticsController()->actionGetData()->data;

            self::assertTrue($payload['success']);
            self::assertSame(0, $this->numericTotal($payload['data']), "Chart data of type {$type} must be empty.");
        }
    }

    public function testAUserWithoutAnEditableSiteExportsNothing(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 2, $siteB => 3]);

        $this->actAsUserRestrictedTo([], self::PERMISSIONS);
        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all']);

        // With nothing to export the action reports through the session,
        // which the test runtime does not have. Reaching it is the outcome.
        try {
            $this->analyticsController()->actionExport();
            self::fail('Expected the export to find no data and report that through the session.');
        } catch (MissingComponentException $e) {
            self::assertStringContainsString('Session', $e->getMessage());
        }

        self::assertSame('', (string)Craft::$app->getResponse()->content);
        self::assertStringNotContainsString((string)$campaign->title, (string)json_encode(Craft::$app->getResponse()->data));
    }

    public function testUsersSeeExactlyTheSitesTheyMayEdit(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);
        $everySite = array_map('intval', Craft::$app->getSites()->getAllSiteIds(true));

        foreach ([
            [[$siteA], 1],
            [[$siteA, $siteB], 3],
            [$everySite, 7],
        ] as [$editable, $expectedRecipients]) {
            $this->actAsUserRestrictedTo($editable, self::PERMISSIONS);
            $variables = $this->indexVariables($campaign);

            self::assertSame($expectedRecipients, $variables['summaryStats']['totalRecipients']);
            self::assertContains($campaign->id, array_column($variables['campaignOptions'], 'value'));
            self::assertSame($this->sortedInts($editable), $this->sortedInts(array_column($variables['sites'], 'id')));

            $rows = array_filter($variables['campaignBreakdown'], static fn(array $row): bool => $row['campaignId'] === $campaign->id);
            self::assertSame($this->sortedInts($editable), $this->sortedInts(array_column($rows, 'siteId')));
            self::assertSame($expectedRecipients, array_sum(array_column($rows, 'totalRecipients')));
        }
    }

    public function testAOneSiteUserExportsOnlyThatSite(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 2, $siteB => 3]);
        $siteAName = (string)Craft::$app->getSites()->getSiteById($siteA)?->name;
        $siteBName = (string)Craft::$app->getSites()->getSiteById($siteB)?->name;
        self::assertNotSame($siteAName, $siteBName);

        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $this->request('POST', [], ['format' => 'csv', 'campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all']);

        $csv = (string)$this->analyticsController()->actionExport()->content;

        self::assertStringContainsString((string)$campaign->title, $csv);
        self::assertStringContainsString($siteAName, $csv);
        self::assertStringNotContainsString($siteBName, $csv);
    }

    public function testAnEmptySiteListMeansNoSiteToTheService(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 2]);
        $analytics = CampaignManager::$plugin->analytics;

        self::assertSame(['all'], array_column($analytics->getCampaignOptions([]), 'value'));
        self::assertSame([], $analytics->getCampaignBreakdown('all', [], 'all'));
        self::assertSame([], $analytics->getCampaignBreakdown((int)$campaign->id, [], 'all'));
        self::assertSame(0, $analytics->getOverviewStats('all', [], 'all')['totalRecipients']);

        self::assertContains($campaign->id, array_column($analytics->getCampaignOptions('all'), 'value'));
        self::assertContains($campaign->id, array_column($analytics->getCampaignOptions([$siteA]), 'value'));
        self::assertContains($campaign->id, array_column($analytics->getCampaignOptions($siteA), 'value'));
        self::assertNotSame([], $analytics->getCampaignBreakdown((int)$campaign->id, 'all', 'all'));
        self::assertNotSame([], $analytics->getCampaignBreakdown((int)$campaign->id, [$siteA], 'all'));
        self::assertNotSame([], $analytics->getCampaignBreakdown((int)$campaign->id, $siteA, 'all'));
    }

    /**
     * @param array<int, int> $recipientsBySite
     */
    private function seedCampaignWithRecipients(array $recipientsBySite): Campaign
    {
        $campaign = $this->seedOwnedCampaign();
        $sentAt = Db::prepareDateForDb(new DateTime());

        foreach ($recipientsBySite as $siteId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->seedOwnedRecipient($campaign, $siteId, ['emailSendDate' => $sentAt]);
            }
        }

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function indexVariables(Campaign $campaign): array
    {
        $this->request('GET', ['campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all']);

        return $this->analyticsController()->actionIndex()->data['variables'];
    }

    private function analyticsController(): CapturingAnalyticsController
    {
        return new CapturingAnalyticsController('analytics', CampaignManager::$plugin);
    }

    private function numericTotal(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_sum(array_map($this->numericTotal(...), $value));
        }

        return 0;
    }

    /**
     * @param array<int|string> $values
     * @return list<int>
     */
    private function sortedInts(array $values): array
    {
        $ints = array_values(array_unique(array_map('intval', $values)));
        sort($ints);

        return $ints;
    }
}
