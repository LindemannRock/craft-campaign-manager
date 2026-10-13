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
use lindemannrock\smsmanager\records\SenderIdRecord;
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
     * @param string|null $senderIdHandle Sender ID handle; null or '' inherits the default sender described in {@see resolveSenderHandle()}
     * @param int|null $siteId Site ID for SMS Manager analytics attribution
     * @return bool
     */
    public function sendSms(
        string $to,
        string $message,
        ?string $language = 'en',
        /** @noinspection PhpUnusedParameterInspection */
        ?string $providerHandle = null,
        ?string $senderIdHandle = null,
        ?int $siteId = null,
    ): bool {
        unset($providerHandle); // Intentionally unused — see method docblock.

        if (!$this->isSmsManagerAvailable()) {
            $this->logError('SMS Manager plugin is not installed or enabled');
            return false;
        }

        $senderIdHandle = $this->resolveSenderHandle($senderIdHandle);

        if ($senderIdHandle === null) {
            $this->logError('No sender ID handle resolved for SMS send — set a Default Sender ID in Campaign Manager or SMS Manager settings, or pass an explicit handle.');
            return false;
        }

        return SmsManager::$plugin->sms->sendWithHandle(
            to: $to,
            message: $message,
            senderIdHandle: $senderIdHandle,
            language: $language ?? 'en',
            sourcePlugin: CampaignManager::$plugin->id,
            siteId: $siteId,
        );
    }

    /**
     * Resolve the sender handle a campaign actually sends through.
     *
     * A campaign's own sender handle is returned as is, even when that sender
     * no longer exists: a named sender is never silently replaced. No sender
     * of its own (null or '') inherits the plugin's `defaultSenderIdHandle`
     * setting; when that is unset too, SMS Manager's configured default
     * sender is used.
     *
     * Dispatch, recipient phone validation, campaign persistence and the
     * campaign form all resolve the sender here, so they agree on which
     * sender, and therefore which provider, a campaign uses.
     *
     * @param string|null $senderHandle The campaign's stored sender handle
     * @return string|null Null when no sender can be resolved
     * @since 5.16.0
     */
    public function resolveSenderHandle(?string $senderHandle): ?string
    {
        if ($senderHandle !== null && $senderHandle !== '') {
            return $senderHandle;
        }

        $pluginDefault = CampaignManager::$plugin->getSettings()->defaultSenderIdHandle;
        if ($pluginDefault !== null && $pluginDefault !== '') {
            return $pluginDefault;
        }

        if (!$this->isSmsManagerAvailable()) {
            return null;
        }

        $default = SmsManager::$plugin->senderIds->getDefaultSenderId();

        return ($default && $default->handle) ? (string) $default->handle : null;
    }

    /**
     * Resolve the provider handle behind a campaign's sender selection.
     *
     * The sender comes from {@see resolveSenderHandle()} and its provider
     * from SMS Manager, so this is the provider the invitation is really
     * sent through. Returns null when SMS Manager is unavailable, no sender
     * resolves, or the resolved sender does not exist or has no provider;
     * the default sender's provider is never substituted for an explicitly
     * selected sender.
     *
     * @param string|null $senderHandle The campaign's stored sender handle
     * @since 5.16.0
     */
    public function resolveProviderHandleForSender(?string $senderHandle): ?string
    {
        if (!$this->isSmsManagerAvailable()) {
            return null;
        }

        $resolvedHandle = $this->resolveSenderHandle($senderHandle);
        if ($resolvedHandle === null) {
            return null;
        }

        $sender = SmsManager::$plugin->senderIds->getSenderIdByHandle($resolvedHandle);

        return ($sender && $sender->providerHandle) ? (string) $sender->providerHandle : null;
    }

    /**
     * Resolve SMS Manager's currently-configured default sender to its
     * provider handle. Powers the country-filter preview on the plugin
     * settings page when the saved default is "Use SMS Manager default"
     * (`defaultSenderIdHandle === ''`). Campaigns resolve their inherited
     * provider through {@see resolveProviderHandleForSender()} instead.
     *
     * Returns null when SMS Manager has no default configured or when the
     * default doesn't resolve to an enabled sender.
     */
    public function getResolvedDefaultSenderProvider(): ?string
    {
        if (!$this->isSmsManagerAvailable()) {
            return null;
        }
        $sender = SmsManager::$plugin->senderIds->getDefaultSenderId();
        return ($sender && $sender->providerHandle) ? (string) $sender->providerHandle : null;
    }

    /**
     * Build the optgroup-structured options array for the plugin settings
     * page's Default Sender ID dropdown. Format matches Craft's
     * `forms.selectField` macro: a flat array of `['label', 'value']`
     * options interspersed with `['optgroup' => 'Group Label']` markers.
     *
     *   [
     *       ['label' => 'Use SMS Manager default (currently: …)', 'value' => ''],
     *       ['optgroup' => 'MPP-SMS'],
     *       ['label' => 'A. Alghanim', 'value' => 'alghanim'],
     *       …
     *       ['optgroup' => 'Test Config Provider'],
     *       ['label' => 'Test Config Sender Dev [Dev]', 'value' => 'dev-test'],
     *   ]
     *
     * The empty option means "use SMS Manager's default sender" and is
     * always offered, since an empty setting is a valid saved choice: while
     * SMS Manager has a default it names it, otherwise it says that none is
     * configured, so the browser never presents a real sender in its place.
     * Campaign forms use {@see getCampaignSenderOptions()}, whose empty
     * option inherits the plugin default instead. Mirrors `formie-sms`'s
     * `buildSenderIdOptions()` for cross-plugin consistency.
     *
     * @return array<int, array{label?: string, value?: string, optgroup?: string}>
     */
    public function getSenderIdOptionsWithGroups(): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        $defaultSender = SmsManager::$plugin->senderIds->getDefaultSenderId();
        $options = [[
            'value' => '',
            'label' => $defaultSender
                ? Craft::t('campaign-manager', 'Use SMS Manager default (currently: {sender})', [
                    'sender' => $this->senderLabel($defaultSender),
                ])
                : Craft::t('campaign-manager', 'Use SMS Manager default (none configured)'),
        ]];

        return array_merge($options, $this->groupedSenderOptions());
    }

    /**
     * Build the options array for a campaign's Sender ID dropdown, in the
     * same format as {@see getSenderIdOptionsWithGroups()}.
     *
     * The empty option means the campaign has no sender of its own and
     * inherits the default resolved by {@see resolveSenderHandle()}: the
     * plugin's default sender, or SMS Manager's when the plugin has none.
     * It is always offered, so a campaign that inherits can be saved as
     * such even while no default resolves.
     *
     * @return array<int, array{label?: string, value?: string, optgroup?: string}>
     * @since 5.16.0
     */
    public function getCampaignSenderOptions(): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        $defaultHandle = $this->resolveSenderHandle(null);
        $defaultSender = $defaultHandle !== null ? SmsManager::$plugin->senderIds->getSenderIdByHandle($defaultHandle) : null;
        $options = [[
            'value' => '',
            'label' => $defaultSender
                ? Craft::t('campaign-manager', 'Use Campaign Manager default (currently: {sender})', [
                    'sender' => $this->senderLabel($defaultSender),
                ])
                : Craft::t('campaign-manager', 'Use Campaign Manager default (none configured)'),
        ]];

        return array_merge($options, $this->groupedSenderOptions());
    }

    /**
     * Every enabled sender of every enabled provider, grouped under its
     * provider in the providers' configured display order so the optgroup
     * order in the dropdown is stable and predictable across renders.
     *
     * @return array<int, array{label?: string, value?: string, optgroup?: string}>
     */
    private function groupedSenderOptions(): array
    {
        $options = [];
        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $allSenders = SmsManager::$plugin->senderIds->getAllSenderIds(true);

        $sendersByProvider = [];
        foreach ($allSenders as $sender) {
            if (!$sender->enabled || !$sender->handle || !$sender->providerHandle) {
                continue;
            }
            $sendersByProvider[$sender->providerHandle][] = $sender;
        }

        foreach ($providers as $provider) {
            if (!$provider->enabled || !$provider->handle) {
                continue;
            }
            $providerSenders = $sendersByProvider[$provider->handle] ?? [];
            if ($providerSenders === []) {
                continue;
            }

            $options[] = ['optgroup' => (string) $provider->name];
            foreach ($providerSenders as $sender) {
                $options[] = [
                    'value' => (string) $sender->handle,
                    'label' => $this->senderLabel($sender),
                ];
            }
        }

        return $options;
    }

    /**
     * A sender's display name, marked when it is a development sender.
     */
    private function senderLabel(SenderIdRecord $sender): string
    {
        $label = (string) $sender->name;
        if ($sender->isDev) {
            $label .= ' ' . Craft::t('campaign-manager', '[Dev]');
        }

        return $label;
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
     * Get allowed countries for a provider.
     *
     * Follows SMS Manager's provider contract: a provider with no country
     * selected, like one listing `*`, allows every country. Country codes
     * are returned upper-case, as SMS Manager compares them.
     *
     * @param string|null $providerHandle Provider handle
     * @return list<string> Country codes (e.g., ['KW', 'SA']) or ['*'] for all
     */
    public function getAllowedCountries(?string $providerHandle = null): array
    {
        if (!$this->isSmsManagerAvailable() || !$providerHandle) {
            return ['*'];
        }

        $countries = SmsManager::$plugin->providers->getAllowedCountries($providerHandle);
        if ($countries === [] || in_array('*', $countries, true)) {
            return ['*'];
        }

        return array_values(array_map(static fn($code): string => strtoupper((string)$code), $countries));
    }

    /**
     * The countries a campaign may send SMS to, resolved through the
     * provider behind its sender (see {@see resolveProviderHandleForSender()})
     * and read with {@see getAllowedCountries()}.
     *
     * Adding a recipient, mapping CSV columns and previewing an import all
     * resolve the countries here, so they agree on which numbers a campaign
     * accepts. No provider is a different answer from a provider that allows
     * every country: without a provider no country can be offered at all.
     *
     * @param string|null $senderHandle The campaign's stored sender handle
     * @return list<string>|null ['*'] when every country is allowed, the
     * allowed country codes otherwise, or null when no provider resolves
     * @since 5.16.0
     */
    public function resolveAllowedCountriesForSender(?string $senderHandle): ?array
    {
        $providerHandle = $this->resolveProviderHandleForSender($senderHandle);

        return $providerHandle === null ? null : $this->getAllowedCountries($providerHandle);
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
