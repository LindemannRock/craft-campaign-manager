<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\controllers\CampaignsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Campaign actions stay inside the sites the acting user may edit.
 *
 * @since 5.16.0
 */
#[CoversClass(CampaignsController::class)]
final class CampaignEditableSiteAccessTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:createCampaigns',
        'campaignManager:editCampaigns',
        'campaignManager:deleteCampaigns',
        'campaignManager:runCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:viewAnalytics',
    ];

    public function testCampaignOpensOnAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['site' => $this->siteHandle($siteA)]);
        $response = $this->campaignsController()->actionEdit((int)$campaign->id);

        self::assertSame((int)$campaign->id, (int)$response->data['variables']['campaign']->id);
        self::assertSame($siteA, (int)$response->data['variables']['campaign']->siteId);
    }

    public function testCampaignDoesNotOpenOnASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['site' => $this->siteHandle($siteB)]);
        $this->assertDenied(fn() => $this->campaignsController()->actionEdit((int)$campaign->id));

        $this->request('GET', ['site' => $this->siteHandle($siteB)]);
        $this->assertDenied(
            fn() => $this->campaignsController()->actionEdit(),
            'A new campaign must not start on a site the user cannot edit.',
        );
    }

    public function testCampaignOpensOnTheFirstEditableSiteWhenNoSiteIsRequested(): void
    {
        [, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteB], self::PERMISSIONS);

        $this->request('GET');
        $response = $this->campaignsController()->actionEdit((int)$campaign->id);

        self::assertSame($siteB, (int)$response->data['variables']['campaign']->siteId);
    }

    public function testUnknownAndForbiddenSitesAreRefusedAlike(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $refusals = [];
        foreach ([$this->siteHandle($siteB), 'noSuchSiteHandle'] as $handle) {
            $this->request('GET', ['site' => $handle]);
            try {
                $this->campaignsController()->actionEdit((int)$campaign->id);
                self::fail("Site {$handle} must be refused.");
            } catch (\yii\web\HttpException $e) {
                $refusals[] = [$e::class, $e->getMessage()];
            }
        }

        self::assertSame($refusals[0], $refusals[1]);
    }

    public function testCampaignSavesOnAnEditableSite(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);

        $this->request('POST', [], $this->savePayload($campaign, $siteB, 'Saved on an editable site'));
        $response = $this->campaignsController()->actionSave();

        self::assertTrue($response->data['success']);
        self::assertSame('Saved on an editable site', $this->persistedTitle($campaign, $siteB));
    }

    public function testCampaignDoesNotSaveOnASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $before = $this->persistedCampaignState($campaign);

        $this->request('POST', [], $this->savePayload($campaign, $siteB, 'Crossed the site boundary'));
        $this->assertDenied(fn() => $this->campaignsController()->actionSave());

        self::assertSame($before, $this->persistedCampaignState($campaign));
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testNewCampaignIsNotCreatedOnASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $title = $this->nextTestMarker('Campaign Manager test ', 'campaign');

        $this->request('POST', [], [
            'siteId' => (string)$siteB,
            'title' => $title,
            'enabled' => '1',
        ]);

        try {
            $this->assertDenied(fn() => $this->campaignsController()->actionSave());
        } finally {
            foreach (Campaign::find()->title($title)->siteId('*')->status(null)->unique()->ids() as $createdId) {
                $this->ownCampaign((int)$createdId);
            }
        }

        self::assertSame(0, (int)Campaign::find()->title($title)->siteId('*')->status(null)->count());
    }

    public function testCampaignIsDeletedFromAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => (string)$siteA]);
        $response = $this->campaignsController()->actionDelete();

        self::assertTrue($response->data['success']);
        self::assertNotEmpty($this->persistedCampaignState($campaign)['deleted']);
    }

    public function testCampaignIsNotDeletedFromASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $before = $this->persistedCampaignState($campaign);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => (string)$siteB]);
        $this->assertDenied(fn() => $this->campaignsController()->actionDelete());

        self::assertSame($before, $this->persistedCampaignState($campaign));
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testCampaignIsNotDeletedByAUserWithoutAnyEditableSite(): void
    {
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([], self::PERMISSIONS);
        $before = $this->persistedCampaignState($campaign);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id]);
        $this->assertDenied(fn() => $this->campaignsController()->actionDelete());

        self::assertSame($before, $this->persistedCampaignState($campaign));
    }

    public function testCampaignTabRendersForAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', [
            'campaignId' => (string)$campaign->id,
            'tab' => 'analytics',
            'site' => $this->siteHandle($siteA),
            'dateRange' => 'all',
        ]);
        $response = $this->campaignsController()->actionRenderTab();

        self::assertIsString($response->data['html']);
        self::assertNotSame('', $response->data['html']);
    }

    public function testCampaignTabDoesNotRenderForASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        foreach (['analytics', 'responses'] as $tab) {
            $this->request('GET', [
                'campaignId' => (string)$campaign->id,
                'tab' => $tab,
                'site' => $this->siteHandle($siteB),
            ]);
            $this->assertDenied(
                fn() => $this->campaignsController()->actionRenderTab(),
                "The {$tab} tab must not render for a site the user cannot edit.",
            );
        }
    }

    public function testCampaignRunsOnAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => (string)$siteA]);
        $response = $this->campaignsController()->actionRunAll();

        self::assertTrue($response->data['success']);
        self::assertSame(1, $response->data['jobsQueued']);
        self::assertSame(1, $this->ownedQueueJobCount());
    }

    public function testCampaignDoesNotRunOnASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        foreach ([(string)$siteB, $siteB . 'abc', '999999'] as $craftedSiteId) {
            $this->request('POST', [], ['campaignId' => (string)$campaign->id, 'siteId' => $craftedSiteId]);
            $this->assertDenied(
                fn() => $this->campaignsController()->actionRunAll(),
                "Site value {$craftedSiteId} must not queue a campaign.",
            );
        }

        self::assertSame(0, $this->ownedQueueJobCount());
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testRunningWithoutASiteQueuesOnlyEditableSites(): void
    {
        [$siteA, $siteB] = $this->siteIds(3);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);

        $this->request('POST', [], ['campaignId' => (string)$campaign->id]);
        $response = $this->campaignsController()->actionRunAll();

        self::assertSame(2, $response->data['jobsQueued']);
        self::assertSame(2, $this->ownedQueueJobCount());
    }

    /**
     * @return array<string, string>
     */
    private function savePayload(Campaign $campaign, int $siteId, string $title): array
    {
        return [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteId,
            'title' => $title,
            'enabled' => '1',
            'formId' => (string)$campaign->formId,
        ];
    }

    private function persistedTitle(Campaign $campaign, int $siteId): ?string
    {
        foreach ($this->persistedCampaignState($campaign)['sites'] as $row) {
            if ((int)$row['siteId'] === $siteId) {
                return $row['title'];
            }
        }

        return null;
    }
}
