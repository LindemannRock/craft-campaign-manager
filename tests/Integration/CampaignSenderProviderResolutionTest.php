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
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Response;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\SmsService;
use lindemannrock\campaignmanager\tests\Stubs\InMemorySession;
use lindemannrock\campaignmanager\tests\Stubs\RecordingSmsManagerSmsService;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use lindemannrock\campaignmanager\tests\Stubs\UnshortenedLinkRecipientsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\tests\Support\SmsManagerResourceOwnership;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * A campaign's SMS provider is the provider of the sender it selected. That
 * one answer is what the campaign stores, what it reads back, what recipient
 * phone numbers are checked against when they are added or imported, and
 * what the invitation is sent through, even when SMS Manager's default
 * sender belongs to another provider with another country policy.
 *
 * Two providers are used throughout: provider A allows Kuwait only and owns
 * SMS Manager's default sender; provider B allows Saudi Arabia only and owns
 * the sender the campaign selects.
 *
 * @since 5.16.0
 */
#[CoversClass(SmsService::class)]
#[CoversClass(CampaignRecord::class)]
final class CampaignSenderProviderResolutionTest extends SiteRestrictedUserTestCase
{
    use SmsManagerResourceOwnership;

    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:editCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
        'campaignManager:importRecipients',
    ];

    private const KUWAIT_NUMBER = '+96597176606';

    private const SAUDI_NUMBER = '+966551234567';

    private const EMIRATI_NUMBER = '+971501234567';

    private ProviderRecord $providerA;

    private SenderIdRecord $senderA;

    private ProviderRecord $providerB;

    private SenderIdRecord $senderB;

    private ?InMemorySession $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new InMemorySession();
        $this->providerA = $this->seedSmsProvider(['KW']);
        $this->senderA = $this->seedSmsSender($this->providerA);
        $this->providerB = $this->seedSmsProvider(['SA']);
        $this->senderB = $this->seedSmsSender($this->providerB);
        $this->useSmsManagerDefaultSender($this->senderA);
        // The campaign's own sender decides; the plugin-wide default follows SMS Manager's
        $this->useCampaignManagerDefaultSender('');
    }

    protected function cleanupExternalState(): void
    {
        $this->session = null;
        $failure = null;

        foreach ([
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

    public function testSavingACampaignStoresTheProviderOfItsSelectedSender(): void
    {
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);
        self::assertSame($this->providerA->handle, CampaignManager::$plugin->sms->getResolvedDefaultSenderProvider());

        $this->request('POST', [], $this->savePayload($campaign, (string)$this->senderB->handle));
        $response = $this->campaignsController()->actionSave();

        self::assertTrue($response->data['success']);
        self::assertSame([$this->senderB->handle, $this->providerB->handle], $this->persistedSenderAndProvider($campaign));
        self::assertSame($this->providerB->handle, $this->reloadCampaign($campaign)->providerHandle);
    }

    public function testAddingARecipientChecksTheCountryAgainstTheSelectedSendersProvider(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $response = $this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA');

        self::assertTrue($response->data['success'] ?? false, 'A Saudi number belongs to the selected provider\'s policy and must be accepted.');
        self::assertSame([self::SAUDI_NUMBER], $this->recipientNumbers($campaign));
    }

    public function testAddingARecipientRejectsACountryOnlyTheDefaultSendersProviderAllows(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $response = $this->addRecipient($campaign, self::KUWAIT_NUMBER, 'KW');

        self::assertSame('campaign-manager/recipients/add', $response->data['template'] ?? null, 'The form must come back with the error.');
        $errors = $response->data['variables']['recipient']->getErrors('sms');
        self::assertCount(1, $errors);
        self::assertStringContainsString('not allowed', $errors[0]);
        self::assertSame([], $this->recipientNumbers($campaign));
    }

    public function testAnImportPreviewChecksTheCountryAgainstTheSelectedSendersProvider(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $preview = $this->previewImport($campaign, [self::SAUDI_NUMBER], '');

        self::assertSame(1, $preview['summary']['validRows']);
        self::assertSame(0, $preview['summary']['errors']);
        self::assertSame(self::SAUDI_NUMBER, $preview['validRows'][0]['sms']);
    }

    public function testAnImportPreviewRejectsACountryOnlyTheDefaultSendersProviderAllows(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $preview = $this->previewImport($campaign, [self::KUWAIT_NUMBER], 'KW');

        self::assertSame(0, $preview['summary']['validRows']);
        self::assertCount(1, $preview['errorRows']);
        self::assertStringContainsString('not allowed', $preview['errorRows'][0]['error']);
    }

    public function testAnInvitationIsSentThroughTheSelectedSenderAndItsProvider(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::SAUDI_NUMBER]);
        $smsManager = new RecordingSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $smsManager);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());

        $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());

        self::assertCount(1, $smsManager->sentMessages);
        $sent = $smsManager->sentMessages[0];
        self::assertSame(self::SAUDI_NUMBER, $sent['to']);
        self::assertSame($this->senderB->handle, $sent['senderIdHandle']);
        self::assertSame($this->providerB->handle, SmsManager::$plugin->senderIds->getSenderIdByHandle($sent['senderIdHandle'])?->providerHandle);
        self::assertSame(
            $this->providerB->handle,
            CampaignManager::$plugin->sms->resolveProviderHandleForSender($campaign->senderHandle),
            'Recipient validation and delivery must agree on the provider.',
        );
        self::assertNotNull($this->reloadRecipient($recipient)->smsSendDate);
    }

    public function testACampaignSavedWithoutAProviderIsResolvedThroughItsSender(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->forgetStoredProvider($campaign);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);
        $stored = $this->reloadCampaign($campaign);
        self::assertNull($stored->providerHandle);

        self::assertSame($this->providerB->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender($stored->senderHandle));
        $response = $this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA');
        self::assertTrue($response->data['success'] ?? false, 'The stored sender, not the default sender, decides the country policy.');
    }

    public function testSavingACampaignSavedWithoutAProviderStoresTheProviderOfItsSender(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $untouched = $this->seedCampaignSendingThrough($this->senderA);
        $this->forgetStoredProvider($campaign);
        $this->forgetStoredProvider($untouched);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $this->request('POST', [], $this->savePayload($campaign, (string)$this->senderB->handle));
        self::assertTrue($this->campaignsController()->actionSave()->data['success']);

        self::assertSame([$this->senderB->handle, $this->providerB->handle], $this->persistedSenderAndProvider($campaign));
        self::assertSame([$this->senderA->handle, null], $this->persistedSenderAndProvider($untouched), 'Only the campaign that was saved may be normalized.');
    }

    public function testACampaignAlreadyStoringItsSendersProviderIsUnchangedBySaving(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $before = $this->persistedSenderAndProvider($campaign);
        self::assertSame([$this->senderB->handle, $this->providerB->handle], $before);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $this->request('POST', [], $this->savePayload($campaign, (string)$this->senderB->handle));
        self::assertTrue($this->campaignsController()->actionSave()->data['success']);

        self::assertSame($before, $this->persistedSenderAndProvider($campaign));
    }

    public function testACampaignWhoseSenderNoLongerExistsHasNoProviderAndSendsNothing(): void
    {
        $missing = strtolower($this->nextTestMarker('cm_test_missing_', 'sender'));
        $campaign = $this->seedCampaignSendingThrough($missing, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::SAUDI_NUMBER]);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        self::assertSame([$missing, null], $this->persistedSenderAndProvider($campaign));
        self::assertNull(CampaignManager::$plugin->sms->resolveProviderHandleForSender($missing));
        // Without a provider there is no country policy; the default sender's policy is not substituted
        self::assertTrue($this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA')->data['success'] ?? false);

        try {
            $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
            self::fail('A campaign whose sender no longer exists must not send.');
        } catch (SendBatchFailedException) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->reloadRecipient($recipient)->smsSendDate);
    }

    public function testACampaignFollowingTheDefaultSenderResolvesTheProviderItWillSendThrough(): void
    {
        $campaign = $this->seedCampaignSendingThrough(null, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::SAUDI_NUMBER]);
        $smsManager = new RecordingSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $smsManager);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());
        self::assertNull($this->reloadCampaign($campaign)->senderHandle);

        // Plugin-wide default unset: SMS Manager's default sender, provider A
        self::assertSame($this->providerA->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender(null));

        // Plugin-wide default set: that sender, provider B, which is also what gets sent through
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        self::assertSame($this->providerB->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender(null));
        $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
        self::assertCount(1, $smsManager->sentMessages);
        self::assertSame($this->senderB->handle, $smsManager->sentMessages[0]['senderIdHandle']);
    }

    public function testSavingACampaignWithTheDefaultOptionStoresNoSender(): void
    {
        $campaign = $this->seedCampaignSendingThrough($this->senderB);
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        $this->request('POST', [], $this->savePayload($campaign, ''));
        self::assertTrue($this->campaignsController()->actionSave()->data['success']);

        self::assertSame([null, null], $this->persistedSenderAndProvider($campaign));
        self::assertNull($this->reloadCampaign($campaign)->senderHandle);
    }

    public function testACampaignWithoutASenderFollowsCampaignManagersDefaultEverywhere(): void
    {
        // Campaign Manager's default is sender B (Saudi Arabia); SMS Manager's is sender A (Kuwait)
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $campaign = $this->seedCampaignSendingThrough(null, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::SAUDI_NUMBER]);
        $smsManager = new RecordingSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $smsManager);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);
        self::assertNull($this->reloadCampaign($campaign)->senderHandle);

        self::assertSame($this->senderB->handle, CampaignManager::$plugin->sms->resolveSenderHandle(null));
        self::assertSame($this->providerB->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender(null));
        self::assertSame($this->providerB->handle, $this->twigResolvedProvider($campaign));
        self::assertTrue($this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA')->data['success'] ?? false);
        self::assertSame('campaign-manager/recipients/add', $this->addRecipient($campaign, self::KUWAIT_NUMBER, 'KW')->data['template'] ?? null);
        self::assertSame(1, $this->previewImport($campaign, [self::SAUDI_NUMBER], '')['summary']['validRows']);
        self::assertSame(0, $this->previewImport($campaign, [self::KUWAIT_NUMBER], 'KW')['summary']['validRows']);

        $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
        self::assertCount(1, $smsManager->sentMessages);
        self::assertSame($this->senderB->handle, $smsManager->sentMessages[0]['senderIdHandle']);
    }

    public function testACampaignWithoutASenderFollowsSmsManagersDefaultWhenCampaignManagerHasNone(): void
    {
        // Campaign Manager has no default (set up above); SMS Manager's default is sender A (Kuwait)
        $campaign = $this->seedCampaignSendingThrough(null, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::KUWAIT_NUMBER]);
        $smsManager = new RecordingSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $smsManager);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        self::assertSame($this->senderA->handle, CampaignManager::$plugin->sms->resolveSenderHandle(null));
        self::assertSame($this->providerA->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender(null));
        self::assertSame($this->providerA->handle, $this->twigResolvedProvider($campaign));
        self::assertTrue($this->addRecipient($campaign, self::KUWAIT_NUMBER, 'KW')->data['success'] ?? false);
        self::assertSame('campaign-manager/recipients/add', $this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA')->data['template'] ?? null);
        self::assertSame(1, $this->previewImport($campaign, [self::KUWAIT_NUMBER], 'KW')['summary']['validRows']);
        self::assertSame(0, $this->previewImport($campaign, [self::SAUDI_NUMBER], '')['summary']['validRows']);

        $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
        self::assertCount(1, $smsManager->sentMessages);
        self::assertSame($this->senderA->handle, $smsManager->sentMessages[0]['senderIdHandle']);
    }

    public function testASelectedSenderOverridesBothDefaults(): void
    {
        $providerC = $this->seedSmsProvider(['AE']);
        $senderC = $this->seedSmsSender($providerC);
        $this->useCampaignManagerDefaultSender((string)$this->senderB->handle);
        $campaign = $this->seedCampaignSendingThrough($senderC, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::EMIRATI_NUMBER]);
        $smsManager = new RecordingSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $smsManager);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());
        $this->actAsUserRestrictedTo([(int)$campaign->siteId], self::PERMISSIONS);

        self::assertSame([$senderC->handle, $providerC->handle], $this->persistedSenderAndProvider($campaign));
        self::assertSame($senderC->handle, CampaignManager::$plugin->sms->resolveSenderHandle($senderC->handle));
        self::assertSame($providerC->handle, CampaignManager::$plugin->sms->resolveProviderHandleForSender($this->reloadCampaign($campaign)->senderHandle));
        self::assertSame($providerC->handle, $this->twigResolvedProvider($campaign));
        self::assertTrue($this->addRecipient($campaign, self::EMIRATI_NUMBER, 'AE')->data['success'] ?? false);
        self::assertSame('campaign-manager/recipients/add', $this->addRecipient($campaign, self::SAUDI_NUMBER, 'SA')->data['template'] ?? null);
        self::assertSame('campaign-manager/recipients/add', $this->addRecipient($campaign, self::KUWAIT_NUMBER, 'KW')->data['template'] ?? null);
        self::assertSame(1, $this->previewImport($campaign, [self::EMIRATI_NUMBER], '')['summary']['validRows']);
        self::assertSame(0, $this->previewImport($campaign, [self::SAUDI_NUMBER], '')['summary']['validRows']);

        $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
        self::assertCount(1, $smsManager->sentMessages);
        self::assertSame($senderC->handle, $smsManager->sentMessages[0]['senderIdHandle']);
    }

    public function testASenderValueThatIsANumberIsNeverLookedUpAsASenderRecordId(): void
    {
        $recordId = (string)$this->senderB->id;
        $campaign = $this->seedCampaignSendingThrough($recordId, 'Please take part.');
        $recipient = $this->seedOwnedRecipient($campaign, (int)$campaign->siteId, ['email' => null, 'sms' => self::SAUDI_NUMBER]);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());

        self::assertSame([$recordId, null], $this->persistedSenderAndProvider($campaign));
        self::assertSame($recordId, CampaignManager::$plugin->sms->resolveSenderHandle($recordId));
        self::assertNull(CampaignManager::$plugin->sms->resolveProviderHandleForSender($recordId));

        try {
            $this->sendBatch($campaign, $recipient)->execute(Craft::$app->getQueue());
            self::fail('A number is a handle that does not exist, not a sender record ID; nothing may be sent.');
        } catch (SendBatchFailedException) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->reloadRecipient($recipient)->smsSendDate);
    }

    /**
     * Save an owned campaign that sends through the given sender: a sender
     * record, a sender handle, or null to follow the default sender.
     */
    private function seedCampaignSendingThrough(SenderIdRecord|string|null $sender, ?string $smsMessage = null): Campaign
    {
        $campaign = $this->seedOwnedCampaign();
        $campaign->senderHandle = $sender instanceof SenderIdRecord ? (string)$sender->handle : $sender;
        if ($smsMessage !== null) {
            $campaign->smsInvitationMessage = $smsMessage;
        }
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * Leave the campaign row as every row was written before the provider
     * was derived on save: the sender stored, the provider column empty.
     */
    private function forgetStoredProvider(Campaign $campaign): void
    {
        Db::update('{{%campaignmanager_campaigns}}', ['providerHandle' => null], ['id' => (int)$campaign->id]);
    }

    /**
     * @return array<string, string>
     */
    private function savePayload(Campaign $campaign, string $senderHandle): array
    {
        return [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$campaign->siteId,
            'title' => (string)$campaign->title,
            'enabled' => '1',
            'formId' => (string)$campaign->formId,
            'senderHandle' => $senderHandle,
        ];
    }

    /**
     * @return array{string|null, string|null}
     */
    private function persistedSenderAndProvider(Campaign $campaign): array
    {
        $row = (new Query())
            ->select(['senderHandle', 'providerHandle'])
            ->from('{{%campaignmanager_campaigns}}')
            ->where(['id' => (int)$campaign->id])
            ->one();

        if ($row === false) {
            throw new RuntimeException("Campaign {$campaign->id} has no row.");
        }

        return [$row['senderHandle'], $row['providerHandle']];
    }

    private function reloadCampaign(Campaign $campaign): Campaign
    {
        $reloaded = Campaign::find()
            ->id($campaign->id)
            ->siteId($campaign->siteId)
            ->status(null)
            ->one();

        if (!$reloaded instanceof Campaign) {
            throw new RuntimeException("Campaign {$campaign->id} no longer exists.");
        }

        return $reloaded;
    }

    private function sendBatch(Campaign $campaign, RecipientRecord $recipient): SendBatchJob
    {
        return new SendBatchJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => (int)$campaign->siteId,
            'recipientIds' => [(int)$recipient->id],
            'sendSms' => true,
            'sendEmail' => false,
        ]);
    }

    /**
     * Add a recipient with a phone number through the add action, as the
     * add screen posts it. Recipients the action creates become owned.
     */
    private function addRecipient(Campaign $campaign, string $number, string $country): Response
    {
        $campaignId = (int)$campaign->id;
        $this->request('POST', [], [
            'campaignId' => (string)$campaignId,
            'siteId' => (string)$campaign->siteId,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => '',
            'sms' => $number,
            'smsCountry' => $country,
        ]);
        $before = $this->campaignRecipientIds($campaignId);

        try {
            $response = $this->withSession(fn() => $this->recipientsController()->actionAdd());
        } finally {
            foreach (array_diff($this->campaignRecipientIds($campaignId), $before) as $recipientId) {
                $this->ownRecipient($recipientId);
            }
        }

        if (!$response instanceof Response) {
            throw new RuntimeException('The add action returned no response.');
        }

        return $response;
    }

    /**
     * Run the import preview for one phone-number column, as the mapping
     * screen posts it, and return the preview page's variables.
     *
     * @param list<string> $numbers
     * @return array<string, mixed>
     */
    private function previewImport(Campaign $campaign, array $numbers, string $phoneCountry): array
    {
        $campaignId = (int)$campaign->id;
        $rows = [];
        foreach ($numbers as $number) {
            $rows[] = [$this->nextTestMarker('Campaign Manager test ', 'recipient'), $number];
        }

        $this->session()->set('recipient-import', [
            'headers' => ['Name', 'Phone'],
            'allRows' => $rows,
            'rowCount' => count($rows),
            'campaignId' => $campaignId,
            'queueSending' => false,
            'filename' => 'recipients.csv',
        ]);
        $this->request('POST', ['site' => $this->siteHandle((int)$campaign->siteId)], [
            'campaignId' => (string)$campaignId,
            'queueSending' => '0',
            'mapping' => ['0' => 'name', '1' => 'sms'],
            'defaultSiteId' => (string)$campaign->siteId,
            'phoneCountry' => $phoneCountry,
        ]);

        $response = $this->withSession(fn() => $this->recipientsController()->actionPreview());

        return $response->data['variables'];
    }

    /**
     * @return list<string>
     */
    private function recipientNumbers(Campaign $campaign): array
    {
        return array_values(array_map('strval', RecipientRecord::find()
            ->select('sms')
            ->where(['campaignId' => (int)$campaign->id])
            ->andWhere(['not', ['sms' => null]])
            ->orderBy('id')
            ->column()));
    }

    /**
     * @return list<int>
     */
    private function campaignRecipientIds(int $campaignId): array
    {
        return array_map('intval', RecipientRecord::find()
            ->select('id')
            ->where(['campaignId' => $campaignId])
            ->orderBy('id')
            ->column());
    }

    /**
     * The provider the recipients templates show countries for, resolved the
     * way `recipients/add.twig` and `recipients/map.twig` resolve it.
     */
    private function twigResolvedProvider(Campaign $campaign): ?string
    {
        $rendered = Craft::$app->getView()->renderString(
            '{{ craft.campaignManager.sms.resolveProviderHandleForSender(campaign.senderHandle) ?? \'\' }}',
            ['campaign' => $campaign],
        );

        return $rendered === '' ? null : $rendered;
    }

    private function session(): InMemorySession
    {
        if ($this->session === null) {
            throw new RuntimeException('No session is installed.');
        }

        return $this->session;
    }

    /**
     * Run a controller action with the in-memory session available as the
     * application session, then put the real application back.
     */
    private function withSession(callable $action): mixed
    {
        $application = Craft::$app;
        if (!$application instanceof \craft\console\Application) {
            throw new RuntimeException('The test runtime is expected to be a console application.');
        }
        $proxy = new SessionApplication($application, $this->session());
        Craft::$app = $proxy;
        \Yii::$app = $proxy;

        try {
            return $action();
        } finally {
            Craft::$app = $application;
            \Yii::$app = $application;
        }
    }
}
