<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\db\Query;
use craft\services\Config;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\Stubs\ControlledMppTransportService;
use lindemannrock\campaignmanager\tests\Stubs\PluginConfigOverridingConfig;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\services\MppTransportService;
use lindemannrock\smsmanager\services\SmsService;
use lindemannrock\smsmanager\SmsManager;

/**
 * Real MPP guard and service in the disposable database, synthetic HTTP only.
 *
 * @since 5.16.0
 */
abstract class MppCampaignTestCase extends InvitationDeliveryTestCase
{
    protected ControlledMppTransportService $guard;
    protected ProviderRecord $provider;
    protected string $credential;
    private ?Config $originalConfig = null;
    private ?array $originalLogSettings = null;
    private array $ownedScopeKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertStringContainsString('/campaign-manager-fixture-', (string)constant('CRAFT_BASE_PATH'));
        self::assertSame(0, (int)(new Query())->from(MppTransportService::TABLE)->count());
        $this->originalConfig = Craft::$app->getConfig();
        Craft::$app->set('config', PluginConfigOverridingConfig::wrapping($this->originalConfig, [
            'sms-manager' => ['security' => ['blockPrivateNetworks' => false, 'allowedApiHosts' => ['mpp.example.test']]],
        ]));
        $this->credential = 'synthetic-' . bin2hex(random_bytes(8));
        $key = 'account:' . hash_hmac('sha256', "sms-manager:mpp-account\0" . $this->credential, Craft::$app->getConfig()->getGeneral()->securityKey);
        $this->ownedScopeKeys = ['transport', $key];
        $this->guard = new ControlledMppTransportService();
        $this->swapPluginComponent('sms-manager', 'mppTransport', $this->guard);
        $this->swapPluginComponent('sms-manager', 'sms', new SmsService());
        $settings = SmsManager::$plugin->getSettings();
        $this->originalLogSettings = [$settings->enableSmsLogs, $settings->enableAnalytics];
        $settings->enableSmsLogs = false;
        $settings->enableAnalytics = false;
        TransportIsolation::$responses = new MockHandler([]);
    }

    protected function mppCampaign(): Campaign
    {
        $this->provider = $this->seedSmsProvider(['*']);
        $this->provider->type = 'mpp-sms';
        $this->provider->setSettingsArray(['apiKey' => $this->credential, 'apiUrl' => 'https://mpp.example.test/send', 'allowedCountries' => ['*']]);
        self::assertTrue(SmsManager::$plugin->providers->saveProvider($this->provider));
        $sender = $this->seedSmsSender($this->provider);
        $campaign = $this->seedDeliverableCampaign();
        $campaign->senderHandle = $sender->handle;
        $this->saveTestElement($campaign, false, true, false);
        return $campaign;
    }

    protected function http(string ...$bodies): void
    {
        TransportIsolation::$responses = new MockHandler(array_map(static fn(string $body): Response => new Response(200, [], $body), $bodies));
    }

    protected function hold(string $code, ?int $delay = null): void
    {
        $result = $this->guard->send($this->credential, static fn(): array => [
            'success' => false, 'failureCode' => $code,
            'failureScope' => $code === 'account-unavailable' ? 'account' : 'transport',
            'retryAfterSeconds' => $delay,
        ]);
        self::assertSame($code, $result['failureCode']);
    }

    protected function cleanupExternalState(): void
    {
        try {
            TransportIsolation::$responses = null;
            if ($this->originalConfig !== null) {
                Craft::$app->set('config', $this->originalConfig);
            }
            if ($this->ownedScopeKeys !== []) {
                Craft::$app->getDb()->createCommand()->delete(MppTransportService::TABLE, ['scopeKey' => $this->ownedScopeKeys])->execute();
                self::assertSame(0, (int)(new Query())->from(MppTransportService::TABLE)->where(['scopeKey' => $this->ownedScopeKeys])->count());
            }
            if ($this->originalLogSettings !== null) {
                $settings = SmsManager::$plugin->getSettings();
                [$settings->enableSmsLogs, $settings->enableAnalytics] = $this->originalLogSettings;
            }
        } finally {
            parent::cleanupExternalState();
        }
    }
}
