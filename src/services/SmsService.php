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

        // Empty string is the "Use SMS Manager default" sentinel that lives
        // at every layer of the dispatch chain (per-campaign senderId field,
        // plugin defaultSenderIdHandle setting). Resolve to SMS Manager's
        // currently-configured default sender. The 8.2 fail-loud guarantee
        // surfaces a clear error if no default is configured plugin-wide.
        if (empty($senderIdHandle)) {
            $default = SmsManager::$plugin->senderIds->getDefaultSenderId();
            $senderIdHandle = $default?->handle;
        }

        if (empty($senderIdHandle)) {
            $this->logError('No sender ID handle resolved for SMS send — set a Default Sender ID in Campaign Manager or SMS Manager settings, or pass an explicit handle.');
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
     * Resolve SMS Manager's currently-configured default sender to its
     * provider handle. Powers the country-filter preview on the settings
     * and campaign-edit pages when the saved sender is the "Use SMS
     * Manager default" sentinel (`senderIdHandle === ''`) — at that point
     * dispatch will route through whatever sms-manager has as its default,
     * and the preview should reflect the actual destination.
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
     * Build the optgroup-structured options array for a single Sender ID
     * dropdown. Format matches Craft's `forms.selectField` macro: a flat
     * array of `['label', 'value']` options interspersed with
     * `['optgroup' => 'Group Label']` markers.
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
     * Drives the single-dropdown UI on both the plugin settings page and
     * the per-campaign edit page. Mirrors `formie-sms`'s `buildSenderIdOptions()`
     * for cross-plugin consistency.
     *
     * @return array<int, array{label?: string, value?: string, optgroup?: string}>
     */
    public function getSenderIdOptionsWithGroups(): array
    {
        $options = [];

        if (!$this->isSmsManagerAvailable()) {
            return $options;
        }

        // "Use SMS Manager default" sentinel — only included when sms-manager
        // actually has a default sender configured. Empty value matches the
        // dispatch sentinel `senderIdHandle === ''` that resolves at send
        // time to whatever the live default is.
        $defaultSender = SmsManager::$plugin->senderIds->getDefaultSenderId();
        if ($defaultSender) {
            $defaultLabel = (string) $defaultSender->name;
            if ($defaultSender->isDev) {
                $defaultLabel .= ' ' . Craft::t('campaign-manager', '[Dev]');
            }
            $options[] = [
                'value' => '',
                'label' => Craft::t('campaign-manager', 'Use SMS Manager default (currently: {sender})', [
                    'sender' => $defaultLabel,
                ]),
            ];
        }

        // Walk providers in their configured display order so the optgroup
        // order in the dropdown is stable and predictable across renders.
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
                $label = (string) $sender->name;
                if ($sender->isDev) {
                    $label .= ' ' . Craft::t('campaign-manager', '[Dev]');
                }
                $options[] = [
                    'value' => (string) $sender->handle,
                    'label' => $label,
                ];
            }
        }

        return $options;
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
     * Get provider options for select fields.
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return array Options array suitable for Craft select fields
     *
     * @deprecated 5.x.0 — kept as Twig-callable API for back-compat with
     *   any custom site template still rendering a separate Provider
     *   dropdown. The plugin's own UI (settings + per-campaign edit) now
     *   uses {@see getSenderIdOptionsWithGroups()} for a single dropdown
     *   with `<optgroup>` provider groupings — provider is implicit via
     *   the chosen sender's `providerHandle`. Removal tracked in
     *   `.internal/todo.md` (see "Dead SmsService methods").
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
     * Get sender ID options for select fields (legacy per-provider variant).
     *
     * @param string|null $providerHandle Optional provider handle to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array Options array suitable for Craft select fields
     *
     * @deprecated 5.x.0 — replaced by {@see getSenderIdOptionsWithGroups()}
     *   which returns a single flat optgroup-structured array suitable for
     *   the new single-dropdown UI. Kept here for back-compat with any
     *   custom site template that still drives a per-provider sender list.
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
     * Get sender ID options as JSON for JavaScript.
     *
     * Returns all sender IDs grouped by provider handle for client-side filtering.
     *
     * @return array Associative array: providerHandle => [senderIdOptions]
     *
     * @deprecated 5.x.0 — replaced by {@see getSenderIdOptionsWithGroups()}.
     *   The old two-dropdown UI used this map to repopulate the sender list
     *   when the provider dropdown changed; the new single-dropdown UI
     *   renders all senders with `<optgroup>` groupings at server-side, so
     *   no client-side rebuild is needed. Kept for custom-template compat.
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
