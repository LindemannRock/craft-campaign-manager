<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\models\Settings;
use lindemannrock\campaignmanager\services\SmsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingSettingsController;
use lindemannrock\campaignmanager\tests\Stubs\EmptySenderIdsService;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\tests\Support\SmsManagerResourceOwnership;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The empty choice in a sender dropdown names what it falls back to. On the
 * plugin settings page it is SMS Manager's default sender; on a campaign it
 * is Campaign Manager's default, which is SMS Manager's default only while
 * Campaign Manager has none of its own. The choice is always offered, and
 * says so when nothing resolves, so a saved form never pins a real sender
 * the administrator did not pick.
 *
 * @since 5.16.0
 */
#[CoversClass(SmsService::class)]
final class CampaignSenderDefaultOptionTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;
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
        $this->useSmsManagerDefaultSender($this->senderA);
        $this->useCampaignManagerDefaultSender('');
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            $this->removeIsolatedPublishPath(...),
            $this->dropImportSession(...),
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

    public function testTheSettingsPageOffersSmsManagersDefaultSender(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);

        $options = CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups();

        self::assertSame(['value' => '', 'label' => 'Use SMS Manager default (currently: ' . $this->senderA->name . ')'], $options[0]);
        self::assertSame(
            'Use SMS Manager default (currently: ' . $this->senderA->name . ')',
            Craft::$app->getView()->renderString('{{ craft.campaignManager.sms.getSenderIdOptionsWithGroups()[0].label }}'),
        );
    }

    public function testTheSettingsPageStillOffersInheritanceWhenSmsManagerHasNoDefault(): void
    {
        $this->useNoSmsManagerDefaultSender();

        $options = CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups();

        self::assertSame(['value' => '', 'label' => 'Use SMS Manager default (none configured)'], $options[0], 'The inherit choice stays, and says that nothing resolves right now.');
        self::assertContains(['value' => (string)$this->senderA->handle, 'label' => (string)$this->senderA->name], array_slice($options, 1), 'The real senders follow it; none of them is presented as the choice.');
        self::assertContains(['value' => (string)$this->senderB->handle, 'label' => (string)$this->senderB->name], array_slice($options, 1));
    }

    public function testTheSettingsPageOffersOnlyInheritanceWhenThereIsNoSender(): void
    {
        $this->swapPluginComponent('sms-manager', 'senderIds', new EmptySenderIdsService());

        self::assertSame([['value' => '', 'label' => 'Use SMS Manager default (none configured)']], CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups());
    }

    public function testTheSettingsPageNamesTheOnlySenderWhenItIsTheDefault(): void
    {
        $this->useSmsManagerDefaultSender($this->senderB);

        $options = CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups();

        self::assertSame(['value' => '', 'label' => 'Use SMS Manager default (currently: ' . $this->senderB->name . ')'], $options[0]);
    }

    public function testSavingTheSettingsKeepsAnInheritedSenderAndStoresAnExplicitOne(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->letCampaignManagerDefaultSenderBeSaved();
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageSettings']);
        $this->installImportSession();

        $this->withinRolledBackTransaction(function(): void {
            $this->saveGeneralSettings(['defaultSenderIdHandle' => (string)$this->senderB->handle]);
            self::assertSame((string)$this->senderB->handle, Settings::loadFromDatabase()->defaultSenderIdHandle, 'An explicit sender is stored.');

            $this->saveGeneralSettings(['defaultSenderIdHandle' => '']);
            self::assertSame('', (string)Settings::loadFromDatabase()->defaultSenderIdHandle, 'Choosing to inherit stores no sender of its own.');
        });
    }

    public function testTheRenderedSettingsPageOffersAndKeepsInheritanceWhenSmsManagerHasNoDefault(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->useNoSmsManagerDefaultSender();
        $this->letCampaignManagerDefaultSenderBeSaved();
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageSettings']);
        $this->installImportSession();

        $select = $this->renderedDefaultSenderSelect();
        self::assertSame('Use SMS Manager default (none configured)', $select['options'][''] ?? null, 'The real page offers inheritance and says that nothing resolves right now.');
        self::assertSame((string)$this->senderA->name, $select['options'][(string)$this->senderA->handle] ?? null, 'The real senders are offered after it.');
        self::assertSame((string)$this->senderB->name, $select['options'][(string)$this->senderB->handle] ?? null);

        $this->withinRolledBackTransaction(function(): void {
            $this->saveGeneralSettings(['defaultSenderIdHandle' => (string)$this->senderB->handle]);
            $select = $this->renderedDefaultSenderSelect(Settings::loadFromDatabase());
            self::assertSame((string)$this->senderB->handle, $select['selected'], 'An explicit sender is shown selected.');

            $this->saveGeneralSettings(['defaultSenderIdHandle' => '']);

            self::assertSame('', (string)Settings::loadFromDatabase()->defaultSenderIdHandle, 'Choosing to inherit stores no sender of its own.');
            self::assertNull(CampaignManager::$plugin->sms->getResolvedDefaultSenderProvider(), 'Nothing resolves while SMS Manager has no default.');
            $select = $this->renderedDefaultSenderSelect(Settings::loadFromDatabase());
            self::assertSame('', $select['selected'], 'The inherited choice is shown selected again.');
            self::assertSame('Use SMS Manager default (none configured)', $select['options'][''], 'It still says that nothing resolves; no real sender took its place.');
        });
    }

    public function testTheCampaignPageOffersCampaignManagersDefaultSender(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);

        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (currently: ' . $this->senderB->name . ')'], $options[0]);
        self::assertSame(array_slice(CampaignManager::$plugin->sms->getSenderIdOptionsWithGroups(), 1), array_slice($options, 1), 'The senders themselves are listed the same way on both pages.');
    }

    public function testTheCampaignPageNamesSmsManagersDefaultWhileCampaignManagerHasNone(): void
    {
        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (currently: ' . $this->senderA->name . ')'], $options[0]);
    }

    public function testTheCampaignPageStillOffersInheritanceWhenNoDefaultResolves(): void
    {
        $this->useCampaignManagerDefaultSender(strtolower($this->nextTestMarker('cm_test_missing_', 'sender')));

        $options = CampaignManager::$plugin->sms->getCampaignSenderOptions();

        self::assertSame(['value' => '', 'label' => 'Use Campaign Manager default (none configured)'], $options[0]);
    }

    public function testEditingACampaignShowsTheCampaignDefaultOptionAndItsInheritedSelection(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], ['campaignManager:manageCampaigns', 'campaignManager:editCampaigns']);
        $this->request('GET', ['site' => $this->siteHandle((int)$campaign->siteId)]);

        $variables = $this->campaignsController()->actionEdit((int)$campaign->id)->data['variables'];

        self::assertSame('Use Campaign Manager default (currently: ' . $this->senderB->name . ')', $variables['senderIdOptions'][0]['label']);
        self::assertInstanceOf(Campaign::class, $variables['campaign']);
        self::assertNull($variables['campaign']->senderHandle, 'A campaign without its own sender inherits; the default is not copied onto it.');
    }

    /**
     * Post the General settings section as the settings page does.
     *
     * @param array<string, string> $settings
     */
    private function saveGeneralSettings(array $settings): void
    {
        $this->request('POST', [], ['section' => 'general', 'settings' => $settings], false);
        $response = $this->withSession(fn() => (new CapturingSettingsController('settings', CampaignManager::$plugin))->actionSave());
        self::assertInstanceOf(\yii\web\Response::class, $response);
        self::assertSame(302, $response->getStatusCode(), 'The settings were expected to save: ' . json_encode($response->data));
        self::assertSame('Settings saved.', $this->session()->getNotice());
    }

    /**
     * Render the real General settings page through this test's own page
     * components and read its Default Sender ID select. Without a model, the page is rendered
     * with the real controller's variables; with one, as the next request
     * would render it after a save, since the plugin caches its settings
     * model for the life of the process.
     *
     * @return array{options: array<string, string>, selected: string|null}
     */
    private function renderedDefaultSenderSelect(?Settings $settings = null): array
    {
        $this->request('GET', [], [], false);
        if ($settings === null) {
            $page = $this->withSession(fn() => (new CapturingSettingsController('settings', CampaignManager::$plugin))->actionIndex())->data;
            self::assertSame('campaign-manager/settings/general', $page['template']);
            $variables = $page['variables'];
        } else {
            $variables = ['settings' => $settings, 'readOnly' => false];
        }

        $html = $this->renderCpTemplateWithSession('campaign-manager/settings/general', $variables);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }
        $select = $document->getElementById('defaultSenderIdHandle');
        self::assertInstanceOf(\DOMElement::class, $select, 'The page renders the Default Sender ID select.');

        $options = [];
        $selected = null;
        foreach ($select->getElementsByTagName('option') as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
            if ($option->hasAttribute('selected')) {
                $selected = $option->getAttribute('value');
            }
        }

        return ['options' => $options, 'selected' => $selected];
    }

    /**
     * Run the work inside a database transaction that is always rolled back,
     * so the project's settings row is as it was afterwards.
     */
    private function withinRolledBackTransaction(callable $work): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $work();
        } finally {
            $transaction->rollBack();
        }
    }

    public function testANewCampaignInheritsRatherThanCopyingTheDefault(): void
    {
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $this->actAsUserRestrictedTo([$siteId], ['campaignManager:manageCampaigns', 'campaignManager:createCampaigns']);
        $this->request('GET', ['site' => $this->siteHandle($siteId)]);

        $variables = $this->campaignsController()->actionEdit()->data['variables'];

        self::assertNull($variables['campaign']->senderHandle);
        self::assertSame('', $variables['senderIdOptions'][0]['value']);
    }
}
