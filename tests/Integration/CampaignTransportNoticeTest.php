<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Query;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\ProviderTransportTestCase;
use lindemannrock\campaignmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\services\TransportService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real campaign pages render safe, permission-aware, read-only hold notices.
 *
 * @since 5.16.0
 */
final class CampaignTransportNoticeTest extends ProviderTransportTestCase
{
    use ImportSessionSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        try {
            $this->removeIsolatedPublishPath();
            $this->dropImportSession();
        } finally {
            parent::cleanupExternalState();
        }
    }

    public static function pages(): iterable
    {
        yield 'edit manual denied' => [false, false, false];
        yield 'edit timed permitted' => [false, true, true];
        yield 'recipients manual permitted' => [true, false, true];
        yield 'recipients timed denied' => [true, true, false];
        yield 'provider permissions without plugin access' => [false, false, true, false];
    }

    #[DataProvider('pages')]
    public function testCampaignPageShowsOnlySafeRecoveryAndPermittedStatusLink(bool $recipients, bool $timed, bool $permitted, bool $pluginAccess = true): void
    {
        $campaign = $this->mppCampaign();
        $this->provider->name = '<img src=x onerror=alert(1)>';
        self::assertTrue($this->provider->save(false));
        $permissions = ['campaignManager:manageCampaigns', 'campaignManager:editCampaigns', 'campaignManager:manageRecipients'];
        if ($pluginAccess) {
            $permissions[] = 'accessPlugin-sms-manager';
        }
        if ($permitted) {
            $permissions = array_merge($permissions, ['smsManager:manageProviders', 'smsManager:editProviders']);
        }
        $user = $this->actAsUserRestrictedTo([$this->siteId], $permissions);
        $this->hold($timed ? 'ip-blocked' : 'account-unavailable', $timed ? 120 : null);
        $before = TransportIsolation::$interceptedRequests;
        $queuedBefore = $this->queuedJobs((int)$campaign->id);
        $html = $this->page($campaign, $recipients);
        $notice = CampaignManager::$plugin->sms->getTransportHoldNotice($campaign->senderHandle);
        self::assertSame(['manual', 'recoveryAt', 'statusUrl'], array_keys($notice));
        self::assertStringContainsString('campaign-sms-transport-notice', $html);
        self::assertStringContainsString('SMS sending is paused.', $html);
        self::assertStringContainsString('Email continues independently.', $html);
        self::assertSame($permitted && $pluginAccess, str_contains($html, '#sms-transport-status'));
        self::assertSame($timed, str_contains($html, 'Timed transport recovery:'));
        if ($timed) {
            self::assertInstanceOf(\DateTime::class, $notice['recoveryAt']);
            self::assertSame($this->guard->time + 120, $notice['recoveryAt']->getTimestamp());
            self::assertStringContainsString(\lindemannrock\base\helpers\DateFormatHelper::formatDatetime($notice['recoveryAt']), $html);
        }
        self::assertSame(!$timed, str_contains($html, 'Resolve the issue and release any hold'));
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString($this->credential, $html);
        self::assertStringNotContainsString('release-transport-hold', $html);
        self::assertStringNotContainsString('mpp-release-hold', $html);
        $holds = $this->guard->status($this->guard->contextForProvider($this->provider))['holds'];
        foreach ($holds as $hold) {
            self::assertStringNotContainsString($hold['token'], $html);
        }
        try {
            self::assertTrue($this->guard->release($this->guard->contextForProvider($this->provider), $holds[0]['lane'], $holds[0]['token'], (int)$user->id));
            self::assertNull(CampaignManager::$plugin->sms->getTransportHoldNotice($campaign->senderHandle));
            self::assertStringNotContainsString('campaign-sms-transport-notice', $this->page($campaign, $recipients));
            self::assertSame($before, TransportIsolation::$interceptedRequests);
            self::assertSame($queuedBefore, $this->queuedJobs((int)$campaign->id));
        } finally {
            $releaseIds = (new Query())->select('id')->from(TransportService::RELEASE_TABLE)->where(['releasedBy' => $user->id])->column();
            if ($releaseIds !== []) {
                Craft::$app->getDb()->createCommand()->delete(TransportService::RELEASE_TABLE, ['id' => $releaseIds])->execute();
            }
        }
    }

    public function testNoticeFollowsTheCanonicalDefaultAndOnlyTheEffectiveAccountLane(): void
    {
        $campaign = $this->mppCampaign();
        $this->hold('account-unavailable');
        $service = CampaignManager::$plugin->sms;
        $settings = CampaignManager::$plugin->getSettings();
        $original = $settings->defaultSenderIdHandle;
        try {
            $settings->defaultSenderIdHandle = $campaign->senderHandle;
            self::assertNotNull($service->getTransportHoldNotice(null));
            self::assertNull($service->getTransportHoldNotice('synthetic-missing-sender'));
            $providerSettings = $this->provider->getSettingsArray();
            $sender = \lindemannrock\smsmanager\SmsManager::$plugin->senderIds->getSenderIdByHandle($campaign->senderHandle);
            $sender->isDev = true;
            self::assertTrue(\lindemannrock\smsmanager\SmsManager::$plugin->senderIds->saveSenderId($sender));
            $providerSettings['devApiKey'] = 'synthetic-different-dev';
            $this->provider->setSettingsArray($providerSettings);
            self::assertTrue(\lindemannrock\smsmanager\SmsManager::$plugin->providers->saveProvider($this->provider));
            self::assertNull($service->getTransportHoldNotice(null), 'A main-account hold does not pause an independent development account.');
            $providerSettings['devApiKey'] = $this->credential;
            $this->provider->setSettingsArray($providerSettings);
            self::assertTrue(\lindemannrock\smsmanager\SmsManager::$plugin->providers->saveProvider($this->provider));
            self::assertNotNull($service->getTransportHoldNotice(null), 'Identical main/development credentials share their hold.');
        } finally {
            $settings->defaultSenderIdHandle = $original;
        }
    }

    public static function newCampaignHolds(): iterable
    {
        yield 'manual permitted' => [false, true];
        yield 'timed denied' => [true, false];
    }

    #[DataProvider('newCampaignHolds')]
    public function testNewCampaignSenderSelectionShowsOnlyItsApplicableHold(bool $timed, bool $permitted): void
    {
        $heldCampaign = $this->mppCampaign();
        $provider = $this->provider;
        $providerSettings = $provider->getSettingsArray();
        $providerSettings['devApiKey'] = 'synthetic-independent-development';
        $provider->setSettingsArray($providerSettings);
        self::assertTrue(\lindemannrock\smsmanager\SmsManager::$plugin->providers->saveProvider($provider));
        $development = $this->seedSmsSender($provider);
        $development->isDev = true;
        self::assertTrue(\lindemannrock\smsmanager\SmsManager::$plugin->senderIds->saveSenderId($development));
        $this->guard->send($this->guard->contextForProvider($provider), static fn(): array => [
            'success' => false,
            'failureCode' => $timed ? 'rate-limited' : 'account-unavailable',
            'failureScope' => 'account',
            'retryAfterSeconds' => $timed ? 120 : null,
        ]);
        $availableCampaign = $this->transportCampaign('twilio', ['accountSid' => 'synthetic-other-account', 'authToken' => 'synthetic-token']);
        $permissions = ['campaignManager:manageCampaigns', 'campaignManager:createCampaigns'];
        if ($permitted) {
            $permissions = array_merge($permissions, ['accessPlugin-sms-manager', 'smsManager:manageProviders', 'smsManager:editProviders']);
        }
        $this->actAsUserRestrictedTo([$this->siteId], $permissions);
        $settings = CampaignManager::$plugin->getSettings();
        $originalDefault = $settings->defaultSenderIdHandle;
        $before = TransportIsolation::$interceptedRequests;
        try {
            $settings->defaultSenderIdHandle = $heldCampaign->senderHandle;
            $page = $this->pageData(new Campaign(), false);
            $html = $page['html'];
            self::assertStringNotContainsString('campaign-sms-transport-notice', $html, 'New campaigns use the sender-specific notice instead of the full saved-campaign notice.');
            self::assertStringContainsString($timed ? 'SMS Manager has paused sending until' : 'SMS sending is paused until this hold is released in', strip_tags($html));
            self::assertSame($permitted, str_contains($html, '#sms-transport-status'));
            self::assertStringNotContainsString($this->credential, $html);
            self::assertStringNotContainsString('synthetic-independent-development', $html);
            $document = new \DOMDocument();
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $notices = [];
            foreach ($xpath->query('//*[@data-sender-hold]') as $node) {
                self::assertInstanceOf(\DOMElement::class, $node);
                $notices[] = ['value' => $node->getAttribute('data-sender-hold'), 'hidden' => $node->hasAttribute('hidden')];
            }
            self::assertSame([''], array_column(array_filter($notices, static fn(array $notice): bool => !$notice['hidden']), 'value'));
            $scripts = array_values(array_filter(array_merge([], ...array_values($page['js'])), static fn(string $script): bool => str_contains($script, "getElementById('campaign-sender-transport-notices')")));
            self::assertCount(1, $scripts);
            $payload = base64_encode(json_encode([
                'script' => $scripts[0], 'notices' => $notices,
                'selections' => ['', $heldCampaign->senderHandle, $availableCampaign->senderHandle, $development->handle, ''],
            ], JSON_THROW_ON_ERROR));
            $result = \lindemannrock\campaignmanager\tests\Support\CommandRunner::run(['node', '-e', <<<'JS'
const vm = require('node:vm');
const data = JSON.parse(Buffer.from(process.argv[1], 'base64').toString());
let change;
const sender = {value: '', addEventListener: (event, handler) => { if (event === 'change') change = handler; }};
const notices = data.notices.map(notice => ({...notice, getAttribute: () => notice.value}));
const document = {getElementById: id => id === 'senderHandle' ? sender : {querySelectorAll: () => notices}};
vm.runInNewContext(data.script, {document});
const visible = data.selections.map(value => {
    sender.value = value;
    change();
    return notices.filter(notice => !notice.hidden).map(notice => notice.value);
});
process.stdout.write(JSON.stringify(visible));
JS, $payload], dirname(__DIR__, 2));
            self::assertSame(0, $result['exitCode'], $result['stderr']);
            self::assertSame([[''], [$heldCampaign->senderHandle], [], [], ['']], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
            if ($timed) {
                $this->guard->time += 121;
                self::assertStringNotContainsString('data-sender-hold=', $this->pageData(new Campaign(), false)['html']);
            }
            self::assertSame($before, TransportIsolation::$interceptedRequests);
        } finally {
            $settings->defaultSenderIdHandle = $originalDefault;
        }
    }

    private function page(Campaign $campaign, bool $recipients): string
    {
        return $this->pageData($campaign, $recipients)['html'];
    }

    /** @return array{html: string, js: array<int, array<string, string>>} */
    private function pageData(Campaign $campaign, bool $recipients): array
    {
        $this->request('GET', ['site' => $this->siteHandle($this->siteId)]);
        $response = $recipients
            ? $this->recipientsController()->actionIndex((int)$campaign->id)
            : $this->campaignsController()->actionEdit($campaign->id ? (int)$campaign->id : null);
        // SMS Manager's global navigation requires a web application. The
        // campaign page, notice and permission checks run unchanged here;
        // only that unrelated sidebar entry is excluded from this console fixture.
        $sms = \lindemannrock\smsmanager\SmsManager::$plugin;
        $hasCpSection = $sms->hasCpSection;
        try {
            $sms->hasCpSection = false;
            return $this->renderCpPageWithSession($response->data['template'], $response->data['variables']);
        } finally {
            $sms->hasCpSection = $hasCpSection;
        }
    }
}
