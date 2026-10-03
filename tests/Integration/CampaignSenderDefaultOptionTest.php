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
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\SmsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\tests\Support\SmsManagerResourceOwnership;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The empty choice in a sender dropdown names what it falls back to. On the
 * plugin settings page it is SMS Manager's default sender; on a campaign it
 * is Campaign Manager's default, which is SMS Manager's default only while
 * Campaign Manager has none of its own.
 *
 * @since 5.16.0
 */
#[CoversClass(SmsService::class)]
final class CampaignSenderDefaultOptionTest extends SiteRestrictedUserTestCase
{
    use SmsManagerResourceOwnership;

    private ProviderRecord $providerA;

    private SenderIdRecord $senderA;

    private ProviderRecord $providerB;

    private SenderIdRecord $senderB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerA = $this->seedSmsProvider(['KW']);
        $this->senderA = $this->seedSmsSender($this->providerA);
        $this->providerB = $this->seedSmsProvider(['SA']);
        $this->senderB = $this->seedSmsSender($this->providerB);
        $this->useSmsManagerDefaultSender($this->senderA);
        $this->useCampaignManagerDefaultSender('');
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            parent::cleanupExternalState(...),
            $this->cleanupSmsManagerResources(...),
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

    public function testTheSettingsPageOffersSmsManagersDefaultSender(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);

        $options = CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups();

        self::assertSame(['value' => '', 'label' => 'Use SMS Manager default (currently: ' . $this->senderA->name . ')'], $options[0]);
        self::assertSame(
            'Use SMS Manager default (currently: ' . $this->senderA->name . ')',
            Craft::$app->getView()->renderString('{{ craft.campaignManager.sms.getSenderIdOptionsWithGroups()[0].label }}'),
        );
    }

    public function testTheCampaignPageOffersCampaignManagersDefaultSender(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);

        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (currently: ' . $this->senderB->name . ')'], $options[0]);
        self::assertSame(array_slice(CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups(), 1), array_slice($options, 1), 'The senders themselves are listed the same way on both pages.');
    }

    public function testTheCampaignPageNamesSmsManagersDefaultWhileCampaignManagerHasNone(): void
    {
        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (currently: ' . $this->senderA->name . ')'], $options[0]);
    }

    public function testTheCampaignPageStillOffersInheritanceWhenNoDefaultResolves(): void
    {
        $this->useCampaignManagerDefaultSender(strtolower($this->nextTestMarker('cm_test_missing_', 'sender')));

        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (none configured)'], $options[0]);
    }

    public function testEditingACampaignShowsTheCampaignDefaultOptionAndItsInheritedSelection(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], ['campaignManager:manageCampaigns', 'campaignManager:editCampaigns']);
        $this->request('GET', ['site' => $this->siteHandle((int)$campaign->siteId)]);

        $variables = $this->campaignsController()->actionEdit((int)$campaign->id)->data['variables'];

        self::assertSame('Use Campaign Manager default (currently: ' . $this->senderB->name . ')', $variables['senderIdOptions'][0]['label']);
        self::assertInstanceOf(Campaign::class, $variables['campaign']);
        self::assertNull($variables['campaign']->senderHandle, 'A campaign without its own sender inherits; the default is not copied onto it.');
    }

    public function testANewCampaignInheritsRatherThanCopyingTheDefault(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $this->actAsUserRestrictedTo([$siteId], ['campaignManager:manageCampaigns', 'campaignManager:createCampaigns']);
        $this->request('GET', ['site' => $this->siteHandle($siteId)]);

        $variables = $this->campaignsController()->actionEdit()->data['variables'];

        self::assertNull($variables['campaign']->senderHandle);
        self::assertSame('', $variables['senderIdOptions'][0]['value']);
    }
}
