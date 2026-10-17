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
use craft\helpers\DateTimeHelper;
use craft\services\Config;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\Stubs\ControlledTransportService;
use lindemannrock\campaignmanager\tests\Stubs\PluginConfigOverridingConfig;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\services\SmsService;
use lindemannrock\smsmanager\services\TransportService;
use lindemannrock\smsmanager\SmsManager;

/**
 * Real shared guard and service in the disposable database, synthetic HTTP only.
 *
 * @since 5.16.0
 */
abstract class ProviderTransportTestCase extends InvitationDeliveryTestCase
{
    protected ControlledTransportService $guard;
    protected ProviderRecord $provider;
    protected string $credential;
    private ?Config $originalConfig = null;
    private ?array $originalLogSettings = null;
    private array $ownedScopes = [];
    private bool $clockPaused = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertStringContainsString('/campaign-manager-fixture-', (string)constant('CRAFT_BASE_PATH'));
        self::assertSame(0, (int)(new Query())->from(TransportService::TABLE)->count());
        $this->originalConfig = Craft::$app->getConfig();
        Craft::$app->set('config', PluginConfigOverridingConfig::wrapping($this->originalConfig, [
            'sms-manager' => ['security' => ['blockPrivateNetworks' => false, 'allowedApiHosts' => ['mpp.example.test', 'api.twilio.com']]],
        ]));
        $this->credential = 'synthetic-' . bin2hex(random_bytes(8));
        $this->guard = new ControlledTransportService();
        DateTimeHelper::pause(new \DateTime('@' . $this->guard->time));
        $this->clockPaused = true;
        $this->swapPluginComponent('sms-manager', 'transport', $this->guard);
        $this->swapPluginComponent('sms-manager', 'sms', new SmsService());
        $settings = SmsManager::$plugin->getSettings();
        $this->originalLogSettings = [$settings->enableSmsLogs, $settings->enableAnalytics];
        $settings->enableSmsLogs = false;
        $settings->enableAnalytics = false;
        TransportIsolation::$responses = new MockHandler([]);
    }

    protected function mppCampaign(): Campaign
    {
        return $this->transportCampaign('mpp-sms', ['apiKey' => $this->credential, 'apiUrl' => 'https://mpp.example.test/send']);
    }

    protected function transportCampaign(string $type, array $settings, bool $development = false): Campaign
    {
        $this->provider = $this->seedSmsProvider(['*']);
        $this->provider->type = $type;
        $this->provider->setSettingsArray($settings + ['allowedCountries' => ['*']]);
        self::assertTrue(SmsManager::$plugin->providers->saveProvider($this->provider));
        $context = $this->guard->contextForProvider($this->provider);
        if ($context !== null) {
            $this->ownedScopes[] = ['providerType' => $context->providerType, 'scopeKey' => array_values($context->scopes())];
        }
        $sender = $this->seedSmsSender($this->provider);
        $sender->isDev = $development;
        self::assertTrue(SmsManager::$plugin->senderIds->saveSenderId($sender));
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
        $result = $this->guard->send($this->guard->contextForProvider($this->provider), static fn(): array => [
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
            if ($this->clockPaused) {
                DateTimeHelper::resume();
                $this->clockPaused = false;
            }
            if ($this->originalConfig !== null) {
                Craft::$app->set('config', $this->originalConfig);
            }
            foreach ($this->ownedScopes as $scope) {
                Craft::$app->getDb()->createCommand()->delete(TransportService::TABLE, $scope)->execute();
                self::assertSame(0, (int)(new Query())->from(TransportService::TABLE)->where($scope)->count());
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
