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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Support\SeedsRatedResponses;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\formieratingfield\fields\Rating;
use PHPUnit\Framework\Attributes\CoversClass;
use verbb\formie\elements\Form;

/**
 * The rating analytics a template or service call asks for with the site
 * scope `all` cover every site of the project, whoever is logged in: a
 * visitor who is not logged in, a user who may edit one site and a user who
 * may edit every site all receive the same answer. An explicit site, a list
 * of sites and an empty list keep their meaning, and the control panel keeps
 * passing the sites its user may edit.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
final class AnalyticsPublicRatingScopeTest extends SiteRestrictedUserTestCase
{
    use SeedsRatedResponses;

    protected function setUp(): void
    {
        parent::setUp();

        if (!PluginHelper::isPluginEnabled('formie-rating-field') || !class_exists(Rating::class)) {
            self::markTestSkipped('Rating analytics need the Formie rating field plugin.');
        }
    }

    public function testEveryIdentityGetsTheSameAllSiteRatingAnalytics(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedTwoSiteCampaign($form, $siteA, $siteB);
        $campaignId = (int)$campaign->id;

        $byIdentity = [];
        foreach ($this->identities($siteA) as $name => $actAs) {
            $actAs();
            $byIdentity[$name] = $this->ratingAnalytics($campaignId, 'all', $fieldId);
        }

        self::assertSame(3, $byIdentity['every site']['stats']['totalResponses'], 'All three responses, on both sites, are counted.');
        self::assertSame(['stats', 'fields', 'trend', 'distribution', 'breakdown', 'raw'], array_keys($byIdentity['every site']));
        self::assertEquals($byIdentity['every site'], $byIdentity['one site'], 'A user who may edit one site gets the same all-site answer.');
        self::assertEquals($byIdentity['every site'], $byIdentity['anonymous'], 'A visitor who is not logged in gets the same all-site answer.');
        self::assertCount(count(Craft::$app->getSites()->getAllSiteIds(true)), $byIdentity['anonymous']['breakdown']['rows'], 'The breakdown lists the campaign on every site of the project.');
        // A raw response row lists the site name second
        $rawSiteNames = array_map(static fn(array $row): string => (string)$row[1], $byIdentity['anonymous']['raw']['rows']);
        sort($rawSiteNames);
        $expectedSiteNames = [$this->siteName($siteA), $this->siteName($siteA), $this->siteName($siteB)];
        sort($expectedSiteNames);
        self::assertSame($expectedSiteNames, $rawSiteNames, 'The raw responses come from both sites.');
    }

    public function testExplicitSiteScopesKeepTheirMeaning(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedTwoSiteCampaign($form, $siteA, $siteB);
        $campaignId = (int)$campaign->id;
        $this->actAsAnonymousVisitor();
        $analytics = CampaignManager::$plugin->analytics;

        self::assertSame(2, $analytics->getRatingStats($campaignId, $siteA, 'all', 'sent', $fieldId)['totalResponses'], 'One site by ID.');
        self::assertSame(1, $analytics->getRatingStats($campaignId, [$siteB], 'all', 'sent', $fieldId)['totalResponses'], 'One site in a list.');
        self::assertSame(3, $analytics->getRatingStats($campaignId, [$siteA, $siteB], 'all', 'sent', $fieldId)['totalResponses'], 'Both sites in a list.');
        self::assertSame(3, $analytics->getRatingStats($campaignId, 'all', 'all', 'sent', $fieldId)['totalResponses'], 'Every site.');
        self::assertSame([], $analytics->getRatingFieldsInScope($campaignId, [], 'all'), 'An empty list is no site.');
        self::assertSame(0, $analytics->getRatingStats($campaignId, [], 'all', 'sent', $fieldId)['totalResponses'], 'An empty list has no responses.');
        self::assertSame([], $analytics->getCampaignRatingBreakdown($campaignId, [], 'all', 'sent', $fieldId)['rows']);
        self::assertSame([], $analytics->getRatingDistribution($campaignId, [], 'all', 'sent', $fieldId)['data']);
    }

    public function testTheControlPanelStillShowsARestrictedUserOnlyTheirSites(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedTwoSiteCampaign($form, $siteA, $siteB);
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:viewAnalytics']);

        $this->request('GET', ['campaign' => (string)$campaign->id, 'dateRange' => 'all', 'siteId' => 'all', 'rating' => (string)$fieldId]);
        $variables = (new \lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController('analytics', CampaignManager::$plugin))->actionIndex()->data['variables'];

        self::assertSame(2, $variables['ratingStats']['totalResponses'], 'The analytics page passes the sites its user may edit, never the whole project.');
        self::assertCount(1, $variables['campaignRatingBreakdown']['rows']);
        self::assertSame($siteA, $variables['campaignRatingBreakdown']['rows'][0]['siteId']);
    }

    private function siteName(int $siteId): string
    {
        return (string)Craft::$app->getSites()->getSiteById($siteId)?->name;
    }

    /**
     * A campaign with two rated responses on the first site and one on the
     * second.
     */
    private function seedTwoSiteCampaign(Form $form, int $siteA, int $siteB): Campaign
    {
        $campaign = $this->seedCampaign($form, ['siteId' => $siteA]);
        $this->ownCampaign((int)$campaign->id);
        $now = new \DateTime();
        $this->seedRatedResponse($campaign, $form, $siteA, $now, $now, 5);
        $this->seedRatedResponse($campaign, $form, $siteA, $now, $now, 4);
        $this->seedRatedResponse($campaign, $form, $siteB, $now, $now, 2);

        return $campaign;
    }

    /**
     * The identities to compare, each as a callable that makes it the
     * current one.
     *
     * @return array<string, callable(): void>
     */
    private function identities(int $siteA): array
    {
        $allSiteIds = array_values(array_map('intval', Craft::$app->getSites()->getAllSiteIds(true)));

        return [
            'every site' => fn() => $this->actAsUserRestrictedTo($allSiteIds, ['campaignManager:viewAnalytics']),
            'one site' => fn() => $this->actAsUserRestrictedTo([$siteA], ['campaignManager:viewAnalytics']),
            'anonymous' => fn() => $this->actAsAnonymousVisitor(),
        ];
    }

    /**
     * Every rating analytics answer for the campaign and site scope.
     *
     * @return array<string, mixed>
     */
    private function ratingAnalytics(int $campaignId, int|string|array $siteId, int $fieldId): array
    {
        $analytics = CampaignManager::$plugin->analytics;

        return [
            'stats' => $analytics->getRatingStats($campaignId, $siteId, 'all', 'sent', $fieldId),
            'fields' => $analytics->getRatingFieldsInScope($campaignId, $siteId, 'all'),
            'trend' => $analytics->getRatingTrend($campaignId, $siteId, 'all', 'sent', $fieldId),
            'distribution' => $analytics->getRatingDistribution($campaignId, $siteId, 'all', 'sent', $fieldId),
            'breakdown' => $analytics->getCampaignRatingBreakdown($campaignId, $siteId, 'all', 'sent', $fieldId),
            'raw' => $analytics->getRawRatingResponses($campaignId, $siteId, 'all', 'sent', $fieldId),
        ];
    }
}
