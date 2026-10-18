<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use craft\helpers\UrlHelper;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Recipient table state stays on its campaign-scoped page, and campaign edit
 * links remain absolute regardless of the page that renders them.
 *
 * @since 5.16.1
 */
#[CoversClass(\lindemannrock\campaignmanager\elements\Campaign::class)]
#[CoversClass(\lindemannrock\campaignmanager\controllers\RecipientsController::class)]
final class RecipientTableNavigationTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            $this->removeIsolatedPublishPath(...),
            $this->dropImportSession(...),
            parent::cleanupExternalState(...),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testRecipientTableStateUsesTheCampaignScopedPageUrl(): void
    {
        [$siteId] = $this->siteIds(1);
        $siteHandle = $this->siteHandle($siteId);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteId], ['campaignManager:manageRecipients']);
        $this->request('GET', [
            'site' => $siteHandle,
            'status' => 'sent',
            'dateRange' => 'thisMonth',
            'search' => 'A&B',
            'sort' => 'name',
            'dir' => 'asc',
            'page' => 2,
        ], [], false);

        $page = $this->withSession(fn() => $this->recipientsController()->actionIndex((int)$campaign->id))->data;
        $rendered = $this->renderCpPageWithSession($page['template'], $page['variables']);
        $expected = UrlHelper::cpUrl("campaign-manager/campaigns/{$campaign->id}/recipients", ['site' => $siteHandle]);
        $wrong = UrlHelper::cpUrl('campaign-manager/campaigns', ['site' => $siteHandle]);

        self::assertStringContainsString('action="' . htmlspecialchars($expected, ENT_QUOTES) . '"', $rendered['html']);
        self::assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $rendered['html']);
        self::assertStringNotContainsString('action="' . htmlspecialchars($wrong, ENT_QUOTES) . '"', $rendered['html']);

        self::assertSame(1, preg_match('/baseUrl:\s*("[^"]+")/', $rendered['html'], $match));
        self::assertSame($expected, json_decode($match[1], true));
    }

    public function testCampaignEditUrlIsAbsoluteAndSiteScoped(): void
    {
        [$siteId] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $campaign->siteId = $siteId;

        self::assertSame(
            UrlHelper::cpUrl("campaign-manager/campaigns/{$campaign->id}", ['site' => $this->siteHandle($siteId)]),
            $campaign->getCpEditUrl(),
        );
    }
}
