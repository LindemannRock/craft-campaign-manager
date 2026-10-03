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
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\integrations\SmsManagerIntegration;
use lindemannrock\campaignmanager\tests\Support\SmsManagerResourceOwnership;
use lindemannrock\campaignmanager\tests\TestCase;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * SMS Manager asks Campaign Manager what is in use before deleting a provider
 * or a sender. A provider is in use while any campaign sends through one of
 * its senders, or while the plugin-wide default sender belongs to it; a
 * sender is in use while a campaign selected it. Deleting something in use is
 * refused with the campaigns that use it; anything unused can go.
 *
 * @since 5.16.0
 */
#[CoversClass(SmsManagerIntegration::class)]
final class SmsProviderDeletionUsageTest extends TestCase
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
        // Only campaigns reference the test senders unless a test says otherwise
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

    public function testAProviderUsedOnlyByACampaignsSenderIsReportedAsUsed(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);

        self::assertSame([$this->campaignUsage($campaign)], $this->providerUsages($this->providerB));
        self::assertSame([], $this->providerUsages($this->providerA));
    }

    public function testDeletingAProviderUsedByACampaignsSenderIsRefused(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);

        $result = SmsManager::$plugin->providers->deleteProvider((int)$this->providerB->id);

        self::assertFalse($result['success']);
        self::assertStringContainsString('Campaign Manager: ' . $this->campaignUsage($campaign)['label'], $result['error']);
        self::assertSame([$this->campaignUsage($campaign)], $result['usages']);
        self::assertNotNull(ProviderRecord::findOne($this->providerB->id));
    }

    public function testEveryCampaignUsingAProviderIsReportedOnce(): void
    {
        $secondSenderB = $this->seedSmsSender($this->providerB);
        $first = $this->seedCampaignSendingThrough($this->senderB);
        $second = $this->seedCampaignSendingThrough($this->senderB);
        $third = $this->seedCampaignSendingThrough($secondSenderB);

        $usages = $this->providerUsages($this->providerB);

        self::assertCount(3, $usages);
        self::assertEqualsCanonicalizing([
            $this->campaignUsage($first),
            $this->campaignUsage($second),
            $this->campaignUsage($third),
        ], $usages);
    }

    public function testThePluginDefaultSendersProviderIsStillReported(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderA->handle);

        self::assertSame([[
            'plugin' => 'campaign-manager',
            'pluginName' => 'Campaign Manager',
            'label' => 'Plugin Default Sender ID provider',
            'editUrl' => UrlHelper::cpUrl('campaign-manager/settings'),
        ]], $this->providerUsages($this->providerA));
        self::assertFalse(SmsManager::$plugin->providers->deleteProvider((int)$this->providerA->id)['success']);
        self::assertNotNull(ProviderRecord::findOne($this->providerA->id));
    }

    public function testDeletingASenderSelectedByACampaignIsStillRefused(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);

        $result = SmsManager::$plugin->senderIds->deleteSenderId((int)$this->senderB->id);

        self::assertFalse($result['success']);
        self::assertStringContainsString('Campaign Manager: ' . $this->campaignUsage($campaign)['label'], $result['error']);
        self::assertNotNull(SenderIdRecord::findOne($this->senderB->id));
    }

    public function testAProviderNoCampaignSendsThroughCanBeDeleted(): void
    {
        $spare = $this->seedSmsProvider(['*']);

        $result = SmsManager::$plugin->providers->deleteProvider((int)$spare->id);

        self::assertTrue($result['success']);
        self::assertNull(ProviderRecord::findOne($spare->id));
    }

    public function testChangingACampaignsSenderReleasesItsFormerProvider(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        self::assertCount(1, $this->providerUsages($this->providerB));

        $campaign->senderHandle = (string)$this->senderA->handle;
        $this->saveTestElement($campaign, false, true, false);

        self::assertSame([], $this->providerUsages($this->providerB));
        self::assertSame([$this->campaignUsage($campaign)], $this->providerUsages($this->providerA));
        self::assertTrue(SmsManager::$plugin->providers->deleteProvider((int)$this->providerB->id)['success']);
        self::assertNull(ProviderRecord::findOne($this->providerB->id));
    }

    public function testASenderValueThatIsANumberProtectsNothing(): void
    {
        $campaign = $this->seedCampaign($this->seedForm(['comment']));
        $campaign->senderHandle = (string)$this->senderB->id;
        $this->saveTestElement($campaign, false, true, false);

        self::assertSame([], $this->providerUsages($this->providerB));
        self::assertSame([], SmsManager::$plugin->integrations->getSenderIdUsages((int)$this->senderB->id));
    }

    private function seedCampaignSendingThrough(SenderIdRecord $sender): Campaign
    {
        $campaign = $this->seedCampaign($this->seedForm(['comment']));
        $campaign->senderHandle = (string)$sender->handle;
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * @return list<array{plugin: string, pluginName: string, label: string, editUrl: string|null}>
     */
    private function providerUsages(ProviderRecord $provider): array
    {
        return SmsManager::$plugin->integrations->getProviderUsages((int)$provider->id);
    }

    /**
     * The usage SMS Manager shows for a campaign: its form's title, linking to the campaign.
     *
     * @return array{plugin: string, pluginName: string, label: string, editUrl: string|null}
     */
    private function campaignUsage(Campaign $campaign): array
    {
        $form = $campaign->getForm();
        if ($form === null) {
            throw new RuntimeException("Campaign {$campaign->id} has no form.");
        }

        return [
            'plugin' => 'campaign-manager',
            'pluginName' => 'Campaign Manager',
            'label' => (string)$form->title,
            'editUrl' => UrlHelper::cpUrl('campaign-manager/campaigns/' . $campaign->id),
        ];
    }
}
