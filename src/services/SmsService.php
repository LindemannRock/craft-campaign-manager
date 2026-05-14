<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\services;

use Craft;
use craft\base\Component;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Service
 *
 * Wrapper for SMS Manager integration.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class SmsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * Send an SMS via SMS Manager.
     *
     * Routes through `SmsManager::sendWithHandle()` so the call works for
     * both DB-backed and config-only sender IDs (config-only senders have
     * `id = null`, which the older ID-based path would silently drop onto
     * the default sender — see SMS Manager audit findings 7.1 / 8.1).
     *
     * Provider is derived from the sender's `providerHandle` inside SMS
     * Manager; the `$providerHandle` parameter here is retained for
     * backwards compatibility with existing call sites but is no longer
     * consulted at dispatch time.
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string|null $language Message language ('en' or 'ar')
     * @param string|null $providerHandle Accepted for back-compat; ignored. Provider is derived from the sender ID's `providerHandle`.
     * @param string|null $senderIdHandle Sender ID handle (uses plugin's `defaultSenderIdHandle` setting if null)
     * @return bool
     */
    public function sendSms(
        string $to,
        string $message,
        ?string $language = 'en',
        /** @noinspection PhpUnusedParameterInspection */
        ?string $providerHandle = null,
        ?string $senderIdHandle = null,
    ): bool {
        unset($providerHandle); // Intentionally unused — see method docblock.

        if (!$this->isSmsManagerAvailable()) {
            $this->logError('SMS Manager plugin is not installed or enabled');
            return false;
        }

        if ($senderIdHandle === null) {
            $senderIdHandle = CampaignManager::$plugin->getSettings()->defaultSenderIdHandle;
        }

        if (empty($senderIdHandle)) {
            $this->logError('No sender ID handle resolved for SMS send — set defaultSenderIdHandle in Campaign Manager settings, or pass an explicit handle.');
            return false;
        }

        return SmsManager::$plugin->sms->sendWithHandle(
            to: $to,
            message: $message,
            senderIdHandle: $senderIdHandle,
            language: $language ?? 'en',
            sourcePlugin: CampaignManager::$plugin->id,
        );
    }

    /**
     * Check if SMS Manager is installed and enabled
     */
    public function isSmsManagerAvailable(): bool
    {
        return PluginHelper::isPluginInstalled('sms-manager') &&
               PluginHelper::isPluginEnabled('sms-manager');
    }

    /**
     * Get available providers from SMS Manager
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return array Array of providers
     */
    public function getAvailableProviders(bool $enabledOnly = true): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        return SmsManager::$plugin->providers->getAllProviders($enabledOnly);
    }

    /**
     * Get provider options for select fields
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return array Options array suitable for Craft select fields
     */
    public function getProviderOptions(bool $enabledOnly = true): array
    {
        $options = [
            ['label' => Craft::t('campaign-manager', 'Select a provider...'), 'value' => ''],
        ];

        foreach ($this->getAvailableProviders($enabledOnly) as $provider) {
            $options[] = [
                'label' => $provider->name . ' (' . strtoupper($provider->type) . ')',
                'value' => $provider->handle,
            ];
        }

        return $options;
    }

    /**
     * Get available sender IDs from SMS Manager
     *
     * @param string|null $providerHandle Optional provider handle to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array Array of sender IDs
     */
    public function getAvailableSenderIds(?string $providerHandle = null, bool $enabledOnly = true): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        if ($providerHandle) {
            return SmsManager::$plugin->senderIds->getSenderIdsByProvider($providerHandle, $enabledOnly);
        }

        return SmsManager::$plugin->senderIds->getAllSenderIds($enabledOnly);
    }

    /**
     * Get sender ID options for select fields
     *
     * @param string|null $providerHandle Optional provider handle to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array Options array suitable for Craft select fields
     */
    public function getSenderIdOptions(?string $providerHandle = null, bool $enabledOnly = true): array
    {
        $options = [
            ['label' => Craft::t('campaign-manager', 'Select a sender ID...'), 'value' => ''],
        ];

        foreach ($this->getAvailableSenderIds($providerHandle, $enabledOnly) as $senderId) {
            $options[] = [
                'label' => $senderId->name . ' (' . $senderId->senderId . ')',
                'value' => $senderId->handle,
            ];
        }

        return $options;
    }

    /**
     * Get sender ID options as JSON for JavaScript
     *
     * Returns all sender IDs grouped by provider handle for client-side filtering.
     *
     * @return array Associative array: providerHandle => [senderIdOptions]
     */
    public function getSenderIdOptionsByProvider(): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        $result = [];
        $providers = $this->getAvailableProviders(true);

        foreach ($providers as $provider) {
            $senderIds = SmsManager::$plugin->senderIds->getSenderIdsByProvider($provider->handle, true);
            $options = [];

            foreach ($senderIds as $senderId) {
                $options[] = [
                    'label' => $senderId->name . ' (' . $senderId->senderId . ')',
                    'value' => $senderId->handle,
                ];
            }

            $result[$provider->handle] = $options;
        }

        return $result;
    }

    /**
     * Get allowed countries for a provider
     *
     * @param string|null $providerHandle Provider handle
     * @return array Array of country codes (e.g., ['KW', 'SA']) or ['*'] for all
     */
    public function getAllowedCountries(?string $providerHandle = null): array
    {
        if (!$this->isSmsManagerAvailable() || !$providerHandle) {
            return ['*'];
        }

        return SmsManager::$plugin->providers->getAllowedCountries($providerHandle);
    }

    /**
     * Get the default country code for a provider
     *
     * Returns the first allowed country, or 'KW' as fallback.
     *
     * @param string|null $providerHandle Provider handle
     * @return string Country code (e.g., 'KW')
     */
    public function getDefaultCountryForProvider(?string $providerHandle = null): string
    {
        $countries = $this->getAllowedCountries($providerHandle);

        // If wildcard or empty, fall back to AE (UAE)
        if (empty($countries) || $countries === ['*']) {
            return 'AE';
        }

        // Return first allowed country
        return $countries[0];
    }

    /**
     * Check if a country is allowed for a provider
     *
     * @param string|null $providerHandle Provider handle
     * @param string $countryCode Country code to check
     * @return bool
     */
    public function isCountryAllowed(?string $providerHandle, string $countryCode): bool
    {
        if (!$this->isSmsManagerAvailable() || !$providerHandle) {
            return true; // Allow all if SMS Manager not available
        }

        return SmsManager::$plugin->providers->isCountryAllowed($providerHandle, $countryCode);
    }
}
