<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\services\Config;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\tests\Stubs\PluginConfigOverridingConfig;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use RuntimeException;

/**
 * Owns the SMS Manager providers and senders a test creates, and the default
 * sender settings it changes.
 *
 * Providers and senders are saved through SMS Manager's own services and
 * removed by their exact IDs; a row the code under test already deleted is
 * simply gone. The default sender of either plugin is changed in memory only
 * and put back exactly. Campaign Manager's default is pinned through the
 * config service, because a project config file re-applies its value on
 * every settings read.
 *
 * The using test case calls {@see cleanupSmsManagerResources()} from its
 * `cleanupExternalState()`, after the campaigns referencing the senders
 * have been removed.
 *
 * @since 5.16.0
 */
trait SmsManagerResourceOwnership
{
    /**
     * A provider type SMS Manager does not know, so nothing can be sent
     * through a test provider.
     */
    private const SMS_TEST_PROVIDER_TYPE = '__cm_test_stub';

    /**
     * @var list<int>
     */
    private array $ownedSmsProviderIds = [];

    /**
     * @var list<int>
     */
    private array $ownedSmsSenderIdIds = [];

    private bool $smsManagerDefaultSenderChanged = false;

    private ?string $originalSmsManagerDefaultSender = null;

    private bool $campaignManagerDefaultSenderChanged = false;

    private ?string $originalCampaignManagerDefaultSender = null;

    private ?Config $originalConfigService = null;

    /**
     * Save an enabled SMS Manager provider that allows exactly the given countries.
     *
     * @param list<string> $allowedCountries
     */
    protected function seedSmsProvider(array $allowedCountries): ProviderRecord
    {
        $provider = new ProviderRecord();
        $provider->name = $this->nextTestMarker('Campaign Manager test ', 'provider');
        $provider->handle = strtolower($this->nextTestMarker('cm_test_', 'provider'));
        $provider->type = self::SMS_TEST_PROVIDER_TYPE;
        $provider->enabled = true;
        $provider->setSettingsArray(['allowedCountries' => $allowedCountries]);

        if (!SmsManager::$plugin->providers->saveProvider($provider) || $provider->id === null) {
            throw new RuntimeException('Test SMS provider failed to save: ' . json_encode($provider->getErrors()));
        }

        $this->ownedSmsProviderIds[] = (int)$provider->id;

        return $provider;
    }

    /**
     * Save an enabled sender belonging to the given test provider.
     */
    protected function seedSmsSender(ProviderRecord $provider): SenderIdRecord
    {
        $sender = new SenderIdRecord();
        $sender->name = $this->nextTestMarker('Campaign Manager test ', 'sender');
        $sender->handle = strtolower($this->nextTestMarker('cm_test_', 'sender'));
        $sender->senderId = 'CMTEST';
        $sender->providerId = (int)$provider->id;
        $sender->providerHandle = (string)$provider->handle;
        $sender->enabled = true;
        $sender->isDev = false;

        if (!SmsManager::$plugin->senderIds->saveSenderId($sender) || $sender->id === null) {
            throw new RuntimeException('Test SMS sender failed to save: ' . json_encode($sender->getErrors()));
        }

        $this->ownedSmsSenderIdIds[] = (int)$sender->id;

        return $sender;
    }

    /**
     * Make the sender SMS Manager's default for the rest of the test.
     */
    protected function useSmsManagerDefaultSender(SenderIdRecord $sender): void
    {
        $settings = SmsManager::$plugin->getSettings();

        if (!$this->smsManagerDefaultSenderChanged) {
            $this->originalSmsManagerDefaultSender = $settings->defaultSenderIdHandle;
            $this->smsManagerDefaultSenderChanged = true;
        }

        $settings->defaultSenderIdHandle = (string)$sender->handle;
    }

    /**
     * Make the handle Campaign Manager's plugin-wide default sender for the
     * rest of the test. An empty handle means "use SMS Manager's default".
     */
    protected function useCampaignManagerDefaultSender(string $handle): void
    {
        if (!$this->campaignManagerDefaultSenderChanged) {
            $this->originalCampaignManagerDefaultSender = CampaignManager::$plugin->getSettings()->defaultSenderIdHandle;
            $this->campaignManagerDefaultSenderChanged = true;
        }

        $this->originalConfigService ??= Craft::$app->getConfig();
        Craft::$app->set('config', PluginConfigOverridingConfig::wrapping($this->originalConfigService, [
            'campaign-manager' => ['defaultSenderIdHandle' => $handle],
        ]));

        if (CampaignManager::$plugin->getSettings()->defaultSenderIdHandle !== $handle) {
            throw new RuntimeException('The Campaign Manager default sender could not be pinned for the test.');
        }
    }

    /**
     * Put both default senders back and remove every owned sender and provider.
     */
    protected function cleanupSmsManagerResources(): void
    {
        $failure = null;

        foreach ([
            $this->restoreCampaignManagerDefaultSender(...),
            $this->restoreSmsManagerDefaultSender(...),
            $this->deleteOwnedSmsSenders(...),
            $this->deleteOwnedSmsProviders(...),
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

    private function restoreCampaignManagerDefaultSender(): void
    {
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
            $this->originalConfigService = null;
        }

        if ($this->campaignManagerDefaultSenderChanged) {
            // Reading the settings re-applies the project's config file; the
            // in-memory value is put back for projects that have none.
            CampaignManager::$plugin->getSettings()->defaultSenderIdHandle = $this->originalCampaignManagerDefaultSender;
            $this->campaignManagerDefaultSenderChanged = false;
            $this->originalCampaignManagerDefaultSender = null;
        }
    }

    private function restoreSmsManagerDefaultSender(): void
    {
        if (!$this->smsManagerDefaultSenderChanged) {
            return;
        }

        SmsManager::$plugin->getSettings()->defaultSenderIdHandle = $this->originalSmsManagerDefaultSender;
        $this->smsManagerDefaultSenderChanged = false;
        $this->originalSmsManagerDefaultSender = null;
    }

    private function deleteOwnedSmsSenders(): void
    {
        if ($this->ownedSmsSenderIdIds === []) {
            return;
        }

        SenderIdRecord::deleteAll(['id' => $this->ownedSmsSenderIdIds]);

        if ((int)SenderIdRecord::find()->where(['id' => $this->ownedSmsSenderIdIds])->count() !== 0) {
            throw new RuntimeException('Owned SMS senders still exist after cleanup.');
        }

        $this->ownedSmsSenderIdIds = [];
    }

    private function deleteOwnedSmsProviders(): void
    {
        if ($this->ownedSmsProviderIds === []) {
            return;
        }

        ProviderRecord::deleteAll(['id' => $this->ownedSmsProviderIds]);

        if ((int)ProviderRecord::find()->where(['id' => $this->ownedSmsProviderIds])->count() !== 0) {
            throw new RuntimeException('Owned SMS providers still exist after cleanup.');
        }

        $this->ownedSmsProviderIds = [];
    }
}
