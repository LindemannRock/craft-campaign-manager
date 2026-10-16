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
use lindemannrock\campaignmanager\tests\Support\MppCampaignTestCase;
use lindemannrock\campaignmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\services\MppTransportService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real campaign pages render safe, permission-aware, read-only hold notices.
 *
 * @since 5.16.0
 */
final class CampaignTransportNoticeTest extends MppCampaignTestCase
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
        self::assertSame($permitted && $pluginAccess, str_contains($html, '#mpp-transport-status'));
        self::assertSame($timed, str_contains($html, 'Timed transport recovery:'));
        self::assertSame(!$timed, str_contains($html, 'After manual release'));
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString($this->credential, $html);
        self::assertStringNotContainsString('release-transport-hold', $html);
        self::assertStringNotContainsString('mpp-release-hold', $html);
        $holds = $this->guard->status($this->provider->getSettingsArray())['holds'];
        foreach ($holds as $hold) {
            self::assertStringNotContainsString($hold['token'], $html);
        }
        try {
            self::assertTrue($this->guard->release($this->provider->getSettingsArray(), $holds[0]['lane'], $holds[0]['token'], (int)$user->id));
            self::assertNull(CampaignManager::$plugin->sms->getTransportHoldNotice($campaign->senderHandle));
            self::assertStringNotContainsString('campaign-sms-transport-notice', $this->page($campaign, $recipients));
            self::assertSame($before, TransportIsolation::$interceptedRequests);
            self::assertSame($queuedBefore, $this->queuedJobs((int)$campaign->id));
        } finally {
            $releaseIds = (new Query())->select('id')->from(MppTransportService::RELEASE_TABLE)->where(['releasedBy' => $user->id])->column();
            if ($releaseIds !== []) {
                Craft::$app->getDb()->createCommand()->delete(MppTransportService::RELEASE_TABLE, ['id' => $releaseIds])->execute();
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

    private function page(Campaign $campaign, bool $recipients): string
    {
        $this->request('GET', ['site' => $this->siteHandle($this->siteId)]);
        $response = $recipients
            ? $this->recipientsController()->actionIndex((int)$campaign->id)
            : $this->campaignsController()->actionEdit((int)$campaign->id);
        // SMS Manager's global navigation requires a web application. The
        // campaign page, notice and permission checks run unchanged here;
        // only that unrelated sidebar entry is excluded from this console fixture.
        $sms = \lindemannrock\smsmanager\SmsManager::$plugin;
        $hasCpSection = $sms->hasCpSection;
        try {
            $sms->hasCpSection = false;
            return $this->renderCpTemplateWithSession($response->data['template'], $response->data['variables']);
        } finally {
            $sms->hasCpSection = $hasCpSection;
        }
    }
}
