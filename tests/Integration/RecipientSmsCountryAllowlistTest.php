<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\SmsService;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\campaignmanager\tests\Support\SmsManagerResourceOwnership;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The countries a recipient's phone number may belong to follow SMS
 * Manager's provider contract: a provider with no country selected, like one
 * allowing every country, accepts any country; a provider with countries
 * selected accepts only those; and a campaign whose sender resolves to no
 * provider offers no country at all. Adding a recipient, the column-mapping
 * step and the import preview apply one rule.
 *
 * In a CSV, a number written internationally (`+` or `00`) is attributed to
 * one of the provider's countries by its dial code; a number without that
 * mark belongs to the country the administrator selected whenever it is
 * valid there, and is read as country-code digits only when it is not, so a
 * local number is never mistaken for another allowed country whose dial
 * code its first digits happen to spell.
 *
 * @since 5.16.0
 */
#[CoversClass(SmsService::class)]
#[CoversClass(RecipientsController::class)]
final class RecipientSmsCountryAllowlistTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;
    use SmsManagerResourceOwnership;

    private const PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
        'campaignManager:importRecipients',
    ];

    /**
     * A Kuwaiti number, as entered locally and in E.164. Its leading digits
     * "971" coincide with the UAE dial code, so a local number must never be
     * attributed to a country by its digits alone.
     */
    private const KUWAIT_LOCAL = '97176606';

    private const KUWAIT_E164 = '+96597176606';

    /**
     * A Saudi number in E.164.
     */
    private const SAUDI_E164 = '+966501234567';

    /**
     * A US number in E.164; +1 is shared by every NANP country.
     */
    private const US_E164 = '+16502530000';

    /**
     * A New York number as entered locally and in E.164. Its area code "212"
     * is Morocco's dial code.
     */
    private const NEW_YORK_LOCAL = '2125550123';

    private const NEW_YORK_E164 = '+12125550123';

    /**
     * Moroccan numbers in E.164, with the 00 international prefix, and as
     * country-code digits without a `+`.
     */
    private const MOROCCO_E164 = '+212661234567';

    private const MOROCCO_DOUBLE_ZERO = '00212677654321';

    private const MOROCCO_DOUBLE_ZERO_E164 = '+212677654321';

    private const MOROCCO_DIGITS = '212661234567';

    /**
     * A Canadian number in E.164; it shares +1 with the US number.
     */
    private const CANADA_E164 = '+14165550123';

    /**
     * The Kuwaiti local number read as a UAE number, which it also is.
     */
    private const KUWAIT_LOCAL_AS_UAE_E164 = '+97197176606';

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
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

    public function testAProviderWithNoCountrySelectedAcceptsEveryCountry(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider([]));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        self::assertSame(['*'], CampaignManager::$plugin->sms->resolveAllowedCountriesForSender($campaign->senderHandle));
        self::assertSame(['*'], $this->addFormVariables($campaign, $siteA)['allowedCountries'], 'The Add Recipient form offers every country.');
        $recipientId = $this->addRecipientWithPhone($campaign, $siteA, self::KUWAIT_LOCAL, 'KW');
        self::assertSame(self::KUWAIT_E164, RecipientRecord::findOne($recipientId)?->sms);
    }

    public function testAProviderAllowingEveryCountryAcceptsEveryCountry(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['*']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        self::assertSame(['*'], CampaignManager::$plugin->sms->resolveAllowedCountriesForSender($campaign->senderHandle));
        self::assertSame(['*'], $this->addFormVariables($campaign, $siteA)['allowedCountries']);
        $recipientId = $this->addRecipientWithPhone($campaign, $siteA, self::SAUDI_E164, 'SA');
        self::assertSame(self::SAUDI_E164, RecipientRecord::findOne($recipientId)?->sms);
    }

    public function testAProviderWithCountriesSelectedAcceptsOnlyThose(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['kw']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        self::assertSame(['KW'], CampaignManager::$plugin->sms->resolveAllowedCountriesForSender($campaign->senderHandle), 'Country codes are compared upper-case, as SMS Manager compares them.');
        self::assertSame(['KW'], $this->addFormVariables($campaign, $siteA)['allowedCountries']);

        $refused = $this->tryToAddRecipientWithPhone($campaign, $siteA, self::SAUDI_E164, 'SA');
        self::assertSame('campaign-manager/recipients/add', $refused['template'], 'The form is shown again with the error.');
        self::assertSame(['Country SA is not allowed for this campaign\'s SMS provider.'], $refused['variables']['recipient']->getErrors('sms'));
        self::assertSame(['KW'], $refused['variables']['allowedCountries']);

        $recipientId = $this->addRecipientWithPhone($campaign, $siteA, self::KUWAIT_LOCAL, 'KW');
        self::assertSame(self::KUWAIT_E164, RecipientRecord::findOne($recipientId)?->sms);
    }

    public function testACampaignWhoseSenderResolvesToNoProviderOffersNoCountry(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->seedSmsProvider([]);
        $campaign = $this->seedOwnedCampaign();
        $campaign->senderHandle = strtolower($this->nextTestMarker('cm_test_missing_', 'sender'));
        $this->saveTestElement($campaign, false, true, false);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        self::assertNull(CampaignManager::$plugin->sms->resolveAllowedCountriesForSender($campaign->senderHandle), 'No provider is a different state from an unrestricted provider.');
        self::assertNull($this->addFormVariables($campaign, $siteA)['allowedCountries']);
        self::assertNull($this->mapVariables($campaign, $siteA)['allowedCountries']);

        // Without a country offered, the form posts none and the number is refused
        $refused = $this->tryToAddRecipientWithPhone($campaign, $siteA, self::KUWAIT_LOCAL, '');
        self::assertSame(['Please select a phone country.'], $refused['variables']['recipient']->getErrors('sms'));
    }

    public function testTheCsvImportAcceptsLocalAndForeignNumbersForAProviderWithNoCountrySelected(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider([]));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::KUWAIT_LOCAL, self::SAUDI_E164, self::US_E164], 'KW');

        self::assertSame(['*'], $result['map']['allowedCountries'], 'The mapping step offers every country.');
        self::assertSame([], $result['preview']['errorRows'], 'The local number follows the selected country; the international numbers are detected from their dial codes.');
        self::assertSame(3, $result['preview']['summary']['validRows']);
        self::assertSame([self::KUWAIT_E164, self::SAUDI_E164, self::US_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportAcceptsLocalAndForeignNumbersForAProviderAllowingEveryCountry(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['*']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::KUWAIT_LOCAL, self::SAUDI_E164, self::US_E164], 'KW');

        self::assertSame(['*'], $result['map']['allowedCountries']);
        self::assertSame([], $result['preview']['errorRows']);
        self::assertSame(3, $result['preview']['summary']['validRows']);
        self::assertSame([self::KUWAIT_E164, self::SAUDI_E164, self::US_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportRefusesPhoneNumbersOutsideAProvidersCountries(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['KW']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::KUWAIT_LOCAL, self::SAUDI_E164], 'KW');

        self::assertSame(['KW'], $result['map']['allowedCountries']);
        self::assertSame(1, $result['preview']['summary']['validRows']);
        self::assertCount(1, $result['preview']['errorRows']);
        self::assertStringContainsString('does not match selected country (KW)', (string)$result['preview']['errorRows'][0]['error'], 'A foreign number is validated against the only country offered and refused.');
        self::assertSame([self::KUWAIT_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportKeepsALocalNumberOfTheSelectedCountryWhoseDigitsBeginWithAnotherAllowedDialCode(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['US', 'MA']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::NEW_YORK_LOCAL], 'US');

        self::assertSame(['US', 'MA'], $result['map']['allowedCountries']);
        self::assertSame([], $result['preview']['errorRows'], 'A valid US number stays a US number; "212" is not read as Morocco.');
        self::assertSame(1, $result['preview']['summary']['validRows']);
        self::assertSame([self::NEW_YORK_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportNeverNormalizesALocalNumberUnderAnotherAllowedCountryItIsAlsoValidIn(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['KW', 'AE']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::KUWAIT_LOCAL], 'KW');

        self::assertSame([], $result['preview']['errorRows']);
        self::assertSame([self::KUWAIT_E164], $this->phonesOf($result['created']), 'The number is stored for the selected country, not as the UAE number its digits also form.');
        self::assertNotContains(self::KUWAIT_LOCAL_AS_UAE_E164, $this->phonesOf($result['created']));
    }

    public function testTheCsvImportDetectsExplicitInternationalNumbersAmongAProvidersCountries(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['US', 'MA']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::MOROCCO_E164, self::MOROCCO_DOUBLE_ZERO, self::NEW_YORK_E164], 'US');

        self::assertSame([], $result['preview']['errorRows'], 'Numbers written with + or 00 are attributed by their dial code.');
        self::assertSame(3, $result['preview']['summary']['validRows']);
        self::assertSame([self::MOROCCO_E164, self::MOROCCO_DOUBLE_ZERO_E164, self::NEW_YORK_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportStillReadsCountryCodeDigitsWithoutAPlusForAnAllowedCountry(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['US', 'MA']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::MOROCCO_DIGITS], 'US');

        self::assertSame([], $result['preview']['errorRows'], 'The digits are no US number, so they are read as a country code followed by the national number.');
        self::assertSame([self::MOROCCO_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportTellsAllowedCountriesSharingADialCodeApart(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['US', 'CA']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::CANADA_E164, self::US_E164], 'US');

        self::assertSame([], $result['preview']['errorRows'], 'Both +1 numbers are attributed to their own country.');
        self::assertSame([self::CANADA_E164, self::US_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportRefusesAnInternationalNumberOutsideAProvidersCountries(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedCampaignSendingThrough($this->seedSmsProvider(['US', 'MA']));
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $result = $this->importPhoneRows($campaign, $siteA, [self::SAUDI_E164, self::NEW_YORK_LOCAL], 'US');

        self::assertSame(1, $result['preview']['summary']['validRows']);
        self::assertCount(1, $result['preview']['errorRows']);
        self::assertStringContainsString('does not match selected country (US)', (string)$result['preview']['errorRows'][0]['error'], 'A number of a country the provider does not allow is refused.');
        self::assertSame([self::NEW_YORK_E164], $this->phonesOf($result['created']));
    }

    public function testTheCsvImportSkipsPhoneNumbersWhenNoProviderResolves(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->seedSmsProvider([]);
        $campaign = $this->seedOwnedCampaign();
        $campaign->senderHandle = strtolower($this->nextTestMarker('cm_test_missing_', 'sender'));
        $this->saveTestElement($campaign, false, true, false);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        // The mapping step offers no country, so none is posted
        $result = $this->importPhoneRows($campaign, $siteA, [self::KUWAIT_LOCAL], '');

        self::assertNull($result['map']['allowedCountries']);
        self::assertSame(0, $result['preview']['summary']['validRows']);
        self::assertSame(['No phone country configured'], array_column($result['preview']['errorRows'], 'error'));
        self::assertSame([], $result['created']);
    }

    /**
     * A campaign with both messages that sends through a sender of the
     * given provider.
     */
    private function seedCampaignSendingThrough(\lindemannrock\smsmanager\records\ProviderRecord $provider): Campaign
    {
        $sender = $this->seedSmsSender($provider);
        $campaign = $this->seedOwnedCampaign();
        $campaign->senderHandle = (string)$sender->handle;
        $campaign->emailInvitationMessage = 'Please take part.';
        $campaign->smsInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function addFormVariables(Campaign $campaign, int $siteId): array
    {
        $this->request('GET', ['site' => $this->siteHandle($siteId)]);

        return $this->recipientsController()->actionAddForm((int)$campaign->id)->data['variables'];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapVariables(Campaign $campaign, int $siteId): array
    {
        $this->session()->set('recipient-import', [
            'headers' => ['Name', 'Phone'],
            'allRows' => [['Someone', self::KUWAIT_LOCAL]],
            'rowCount' => 1,
            'campaignId' => (int)$campaign->id,
            'queueSending' => false,
            'filename' => 'recipients.csv',
        ]);
        $this->request('GET', ['site' => $this->siteHandle($siteId)]);

        return $this->withSession(fn() => $this->recipientsController()->actionMap((int)$campaign->id))->data['variables'];
    }

    /**
     * Add a recipient with the phone number and country, expecting success.
     */
    private function addRecipientWithPhone(Campaign $campaign, int $siteId, string $phone, string $country): int
    {
        $email = $this->requestAddRecipient($campaign, $siteId, $phone, $country);

        $created = [];
        try {
            $response = $this->withSession(fn() => $this->recipientsController()->actionAdd());
            self::assertTrue($response->data['success'] ?? false, 'The recipient was expected to be added: ' . json_encode($response->data));
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $created[] = (int)$recipientId;
                $this->ownRecipient((int)$recipientId);
            }
        }
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * Add a recipient with the phone number and country, expecting the form
     * to be shown again; returns the captured template and variables.
     *
     * @return array{template: string, variables: array<string, mixed>}
     */
    private function tryToAddRecipientWithPhone(Campaign $campaign, int $siteId, string $phone, string $country): array
    {
        $email = $this->requestAddRecipient($campaign, $siteId, $phone, $country);

        try {
            $data = $this->withSession(fn() => $this->recipientsController()->actionAdd())->data;
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $this->ownRecipient((int)$recipientId);
            }
        }

        self::assertArrayHasKey('template', $data, 'The recipient was expected to be refused: ' . json_encode($data));
        self::assertSame(0, (int)RecipientRecord::find()->where(['email' => $email])->count(), 'A refused recipient is not saved.');

        return $data;
    }

    private function requestAddRecipient(Campaign $campaign, int $siteId, string $phone, string $country): string
    {
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteId,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sms' => $phone,
            'smsCountry' => $country,
        ]);

        return $email;
    }

    /**
     * Run the real CSV chain, upload parsing through import, for rows that
     * carry only a name and a phone number, with sending off.
     *
     * @param list<string> $phones
     * @return array{map: array<string, mixed>, preview: array<string, mixed>, created: list<int>}
     */
    private function importPhoneRows(Campaign $campaign, int $siteId, array $phones, string $phoneCountry): array
    {
        $campaignId = (int)$campaign->id;
        $csv = "Name,Phone\n";
        foreach ($phones as $phone) {
            $csv .= $this->nextTestMarker('Campaign Manager test ', 'recipient') . ',' . $phone . "\n";
        }
        $parsed = $this->parseOwnedCsv($csv);
        $this->session()->set('recipient-import', [
            'headers' => $parsed['headers'],
            'allRows' => $parsed['allRows'],
            'rowCount' => $parsed['rowCount'],
            'campaignId' => $campaignId,
            'queueSending' => false,
            'filename' => 'recipients.csv',
        ]);

        $this->request('GET', ['site' => $this->siteHandle($siteId)]);
        $map = $this->withSession(fn() => $this->recipientsController()->actionMap($campaignId))->data['variables'];

        $this->request('POST', ['site' => $this->siteHandle($siteId)], [
            'campaignId' => (string)$campaignId,
            'queueSending' => '0',
            'mapping' => ['0' => 'name', '1' => 'sms'],
            'defaultSiteId' => (string)$siteId,
            'phoneCountry' => $phoneCountry,
        ]);
        $preview = $this->withSession(fn() => $this->recipientsController()->actionPreview())->data['variables'];

        $before = $this->campaignRecipientIds($campaignId);
        $created = [];
        $this->request('POST', ['site' => $this->siteHandle($siteId)], ['campaignId' => (string)$campaignId]);
        try {
            $this->withSession(fn() => $this->recipientsController()->actionImport());
        } finally {
            $created = array_values(array_diff($this->campaignRecipientIds($campaignId), $before));
            foreach ($created as $recipientId) {
                $this->ownRecipient($recipientId);
            }
        }

        return ['map' => $map, 'preview' => $preview, 'created' => $created];
    }

    /**
     * @param list<int> $recipientIds
     * @return list<string|null>
     */
    private function phonesOf(array $recipientIds): array
    {
        if ($recipientIds === []) {
            return [];
        }

        return array_values(RecipientRecord::find()
            ->select('sms')
            ->where(['id' => $recipientIds])
            ->orderBy('id')
            ->column());
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
}
