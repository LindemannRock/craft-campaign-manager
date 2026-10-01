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
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\CampaignsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\services\RecipientsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use verbb\formie\elements\Form;

/**
 * Campaign analytics in the control panel count only the sites the acting
 * user may edit, while the analytics service itself keeps its public meaning:
 * no site means every site, whoever is asking.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
#[CoversClass(RecipientsService::class)]
#[CoversClass(CampaignsController::class)]
final class CampaignAnalyticsEditableSiteScopeTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:viewAnalytics',
    ];

    public function testAnalyticsWithoutASiteCountEverySiteForAnAnonymousVisitor(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);
        $analytics = CampaignManager::$plugin->analytics;

        $this->actAsAnonymousVisitor();
        self::assertSame([], \Craft::$app->getSites()->getEditableSiteIds());

        self::assertSame(7, $analytics->getCampaignStats((int)$campaign->id, null, 'all')['totalRecipients']);
        self::assertSame(7, $analytics->getCampaignStats((int)$campaign->id, null, 'all')['totalSent']);
        self::assertSame(7, $analytics->getOverviewStats((int)$campaign->id, 'all', 'all')['totalRecipients']);
        self::assertSame(7, array_sum($analytics->getCampaignDailyTrend((int)$campaign->id, null, 'last7days')['sent']));
    }

    public function testAnalyticsWithoutASiteCountEverySiteWhoeverIsLoggedIn(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);
        $analytics = CampaignManager::$plugin->analytics;

        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        self::assertSame([$siteA], \Craft::$app->getSites()->getEditableSiteIds());
        self::assertSame(7, $analytics->getCampaignStats((int)$campaign->id, null, 'all')['totalRecipients']);
        self::assertSame(7, array_sum($analytics->getCampaignDailyTrend((int)$campaign->id, null, 'last7days')['sent']));

        $this->actAsUserRestrictedTo([], self::PERMISSIONS);
        self::assertSame(7, $analytics->getCampaignStats((int)$campaign->id, null, 'all')['totalRecipients']);
    }

    public function testExplicitSiteScopesLimitAnalytics(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);
        $analytics = CampaignManager::$plugin->analytics;
        $this->actAsAnonymousVisitor();

        self::assertSame(2, $analytics->getCampaignStats((int)$campaign->id, $siteB, 'all')['totalRecipients']);
        self::assertSame(3, $analytics->getCampaignStats((int)$campaign->id, [$siteA, $siteB], 'all')['totalRecipients']);
        self::assertSame(3, $analytics->getOverviewStats((int)$campaign->id, [$siteA, $siteB], 'all')['totalRecipients']);
        self::assertSame(1, array_sum($analytics->getCampaignDailyTrend((int)$campaign->id, [$siteA], 'last7days')['sent']));
        self::assertSame(2, array_sum($analytics->getCampaignDailyTrend((int)$campaign->id, $siteB, 'last7days')['sent']));

        self::assertSame(
            0,
            $analytics->getCampaignStats((int)$campaign->id, [], 'all')['totalRecipients'],
            'An empty site list must count nothing rather than every site.',
        );
        self::assertSame(0, array_sum($analytics->getCampaignDailyTrend((int)$campaign->id, [], 'last7days')['sent']));
    }

    public function testAnalyticsTabShowsOnlyEditableSites(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);

        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        self::assertSame(1, $this->cardValue($this->renderTab($campaign, 'analytics', $siteA), 'Recipients'));

        $this->actAsUserRestrictedTo([$siteA, $siteB], self::PERMISSIONS);
        self::assertSame(3, $this->cardValue($this->renderTab($campaign, 'analytics', $siteA), 'Recipients'));
    }

    public function testAnalyticsTabForAUserWithEverySiteCountsEverySite(): void
    {
        [$siteA, $siteB, $siteC] = $this->siteIds(3);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1, $siteB => 2, $siteC => 4]);
        $everySite = array_map('intval', \Craft::$app->getSites()->getAllSiteIds());

        $this->actAsUserRestrictedTo($everySite, self::PERMISSIONS);

        self::assertSame(7, $this->cardValue($this->renderTab($campaign, 'analytics', $siteA), 'Recipients'));
    }

    public function testAnalyticsTabIsRefusedForAUserWithoutAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithRecipients([$siteA => 1]);

        $this->actAsUserRestrictedTo([], self::PERMISSIONS);

        $this->assertDenied(fn() => $this->renderTab($campaign, 'analytics', $siteA));
    }

    public function testResponsesCannotBeFilteredToASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $form = Form::find()->id($campaign->formId)->one();
        self::assertInstanceOf(Form::class, $form);

        $allowed = $this->seedOwnedRecipient($campaign, $siteA, ['email' => 'allowed-response@example.test']);
        $forbidden = $this->seedOwnedRecipient($campaign, $siteB, ['email' => 'private-response@example.test']);
        $this->submitForm($form, $allowed->emailInvitationCode, $siteA);
        $this->submitForm($form, $forbidden->emailInvitationCode, $siteB);
        self::assertNotNull($this->reloadRecipient($forbidden)->submissionId);

        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $recipients = CampaignManager::$plugin->recipients;
        $editable = \Craft::$app->getSites()->getEditableSiteIds();

        self::assertSame([], $recipients->getWithSubmissions((int)$campaign->id, $siteB, 'all', $editable));
        self::assertSame(0, $recipients->countWithSubmissions((int)$campaign->id, $siteB, 'all', $editable));
        self::assertCount(1, $recipients->getWithSubmissions((int)$campaign->id, $siteA, 'all', $editable));
        self::assertCount(1, $recipients->getWithSubmissions((int)$campaign->id, null, 'all', $editable));

        $html = $this->renderTab($campaign, 'responses', $siteA, ['siteFilter' => (string)$siteB]);
        self::assertStringNotContainsString('private-response@example.test', $html);

        $html = $this->renderTab($campaign, 'responses', $siteA);
        self::assertStringContainsString('allowed-response@example.test', $html);
        self::assertStringNotContainsString('private-response@example.test', $html);
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

        self::assertSame(
            array_sum($recipientsBySite),
            (int)RecipientRecord::find()->where(['campaignId' => $campaign->id])->count(),
        );

        return $campaign;
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function renderTab(Campaign $campaign, string $tab, int $siteId, array $queryParams = []): string
    {
        $this->request('GET', array_merge([
            'campaignId' => (string)$campaign->id,
            'tab' => $tab,
            'site' => $this->siteHandle($siteId),
            'dateRange' => 'all',
        ], $queryParams));

        return (string)$this->campaignsController()->actionRenderTab()->data['html'];
    }

    private function cardValue(string $html, string $description): int
    {
        $text = (string)preg_replace('/\s+/', ' ', strip_tags($html));
        if (preg_match('/(\d[\d,]*)\s+' . preg_quote($description, '/') . '\b/', $text, $matches) !== 1) {
            self::fail("The {$description} card was not rendered.");
        }

        return (int)str_replace(',', '', $matches[1]);
    }
}
