<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\integrations;

use Craft;
use craft\helpers\UrlHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\smsmanager\integrations\IntegrationInterface;
use lindemannrock\smsmanager\SmsManager;
use verbb\formie\elements\Form;

/**
 * SMS Manager Integration
 *
 * Reports Campaign Manager usage to SMS Manager so the delete guard in
 * `ProvidersService::deleteProvider()` / `SenderIdsService::deleteSenderId()`
 * blocks deletion of any provider or sender that this plugin is using.
 *
 * Usage sources covered:
 *  - Per-campaign assignment (`CampaignRecord.senderHandle`), reported for the
 *    sender itself and for the provider behind it
 *  - Plugin-default sender (`Settings.defaultSenderIdHandle`)
 *  - The provider implicit in that default sender
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class SmsManagerIntegration implements IntegrationInterface
{
    /**
     * @inheritdoc
     */
    public function getProviderUsages(int $providerId): array
    {
        $usages = [];

        // Every campaign that selected one of this provider's senders depends
        // on the provider: without it those campaigns could no longer send.
        $provider = SmsManager::$plugin->providers->getProviderById($providerId);
        if ($provider !== null && !empty($provider->handle)) {
            $senderHandles = [];
            foreach (SmsManager::$plugin->senderIds->getSenderIdsByProvider((string) $provider->handle) as $sender) {
                if (!empty($sender->handle)) {
                    $senderHandles[] = (string) $sender->handle;
                }
            }

            foreach ($this->campaignsUsingSenders($senderHandles) as $campaign) {
                $usages[] = $this->campaignUsage($campaign);
            }
        }

        // Provider is implicit via the plugin-default sender. Look the
        // sender up, check whether its `providerHandle` resolves to this
        // provider — if so, deleting the provider would orphan the default
        // sender, so report it as a usage. Config-only senders/providers
        // (where `id` is null) can't match a DB-backed `$providerId` and
        // also can't be deleted via CP anyway.
        $settings = CampaignManager::$plugin->getSettings();
        if (!empty($settings->defaultSenderIdHandle)) {
            $sender = SmsManager::$plugin->senderIds->getSenderIdByHandle($settings->defaultSenderIdHandle);
            if ($sender && $sender->providerHandle) {
                $provider = SmsManager::$plugin->providers->getProviderByHandle($sender->providerHandle);
                if ($provider && (int) $provider->id === $providerId) {
                    $usages[] = [
                        'label' => Craft::t('campaign-manager', 'Plugin Default Sender ID provider'),
                        'editUrl' => UrlHelper::cpUrl('campaign-manager/settings'),
                    ];
                }
            }
        }

        return $usages;
    }

    /**
     * @inheritdoc
     */
    public function getSenderIdUsages(int $senderIdId): array
    {
        $usages = [];

        // Resolve the integer id to the sender's handle: campaigns store the
        // sender's handle in `senderHandle`, never its numeric DB id.
        $senderRecord = SmsManager::$plugin->senderIds->getSenderIdById($senderIdId);
        if ($senderRecord !== null && !empty($senderRecord->handle)) {
            foreach ($this->campaignsUsingSenders([(string) $senderRecord->handle]) as $campaign) {
                $usages[] = $this->campaignUsage($campaign);
            }
        }

        // Plugin-default sender — resolve the configured handle and compare
        // IDs. Same null-id safety as getProviderUsages() above.
        $settings = CampaignManager::$plugin->getSettings();
        if (!empty($settings->defaultSenderIdHandle)) {
            $senderId = SmsManager::$plugin->senderIds->getSenderIdByHandle($settings->defaultSenderIdHandle);
            if ($senderId && (int) $senderId->id === $senderIdId) {
                $usages[] = [
                    'label' => Craft::t('campaign-manager', 'Plugin Default Sender ID'),
                    'editUrl' => UrlHelper::cpUrl('campaign-manager/settings'),
                ];
            }
        }

        return $usages;
    }

    /**
     * Campaigns whose selected sender is one of the given handles, oldest first.
     *
     * @param list<string> $senderHandles
     * @return CampaignRecord[]
     */
    private function campaignsUsingSenders(array $senderHandles): array
    {
        if ($senderHandles === []) {
            return [];
        }

        /** @var CampaignRecord[] $campaigns */
        $campaigns = CampaignRecord::find()
            ->where(['senderHandle' => $senderHandles])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $campaigns;
    }

    /**
     * One usage entry per campaign: its form's title while the form exists,
     * linking to the campaign.
     *
     * @return array{label: string, editUrl: string}
     */
    private function campaignUsage(CampaignRecord $campaign): array
    {
        $label = 'Campaign #' . $campaign->id;
        if ($campaign->formId) {
            $form = Form::find()->id($campaign->formId)->one();
            if ($form) {
                $label = $form->title;
            }
        }

        return [
            'label' => $label,
            'editUrl' => UrlHelper::cpUrl('campaign-manager/campaigns/' . $campaign->id),
        ];
    }
}
