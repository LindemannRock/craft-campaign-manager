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
use craft\helpers\App;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\TimeHelper;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\CustomerRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Submission;
use yii\db\ActiveQuery;

/**
 * Customers Service
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class CustomersService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('campaign-manager');
    }

    /**
     * Get a customer query
     */
    public function find(): ActiveQuery
    {
        return CustomerRecord::find();
    }

    /**
     * Find customers by campaign and site
     *
     * @return CustomerRecord[]
     */
    public function findByCampaignAndSite(int $campaignId, int $siteId, string $dateRange = 'all'): array
    {
        $query = CustomerRecord::find()
            ->where([
                'campaignId' => $campaignId,
                'siteId' => $siteId,
            ])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($dateRange !== 'all') {
            $dates = $this->getDateRangeFromParam($dateRange);
            $query->andWhere(['>=', 'dateCreated', $dates['start']->format('Y-m-d 00:00:00')])
                ->andWhere(['<=', 'dateCreated', $dates['end']->format('Y-m-d 23:59:59')]);
        }

        /** @var CustomerRecord[] $result */
        $result = $query->all();

        return $result;
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     */
    public function getDateRangeFromParam(string $dateRange): array
    {
        $endDate = new \DateTime();

        $startDate = match ($dateRange) {
            'today' => new \DateTime(),
            'yesterday' => (new \DateTime())->modify('-1 day'),
            'last7days' => (new \DateTime())->modify('-7 days'),
            'last30days' => (new \DateTime())->modify('-30 days'),
            'last90days' => (new \DateTime())->modify('-90 days'),
            'all' => (new \DateTime())->modify('-365 days'),
            default => (new \DateTime())->modify('-30 days'),
        };

        if ($dateRange === 'yesterday') {
            $endDate = (new \DateTime())->modify('-1 day');
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    /**
     * Get a customer by invitation code
     */
    public function getCustomerByInvitationCode(string $code): ?CustomerRecord
    {
        /** @var CustomerRecord|null $result */
        $result = CustomerRecord::find()
            ->where([
                'or',
                ['emailInvitationCode' => $code],
                ['smsInvitationCode' => $code],
            ])
            ->one();

        return $result;
    }

    /**
     * Generate a unique invitation code
     */
    public function getUniqueInvitationCode(): string
    {
        do {
            $code = StringHelper::randomString(12);
            $customer = CustomerRecord::find()
                ->where([
                    'or',
                    ['emailInvitationCode' => $code],
                    ['smsInvitationCode' => $code],
                ])
                ->one();
        } while (!empty($customer));

        return $code;
    }

    /**
     * Delete a customer by ID
     */
    public function deleteCustomerById(int $id): bool
    {
        return (bool)CustomerRecord::deleteAll(['id' => $id]);
    }

    /**
     * Parse an invitation message with customer data
     */
    public function parseInvitationMessageForCustomer(string $message, CustomerRecord $customerRecord): string
    {
        $campaign = $customerRecord->getCampaign();

        // Build the invitation URL from the plugin's invitation route setting
        $settings = CampaignManager::$plugin->getSettings();
        $invitationRoute = $settings->invitationRoute ?? 'campaign-manager/invitation';

        // Get the customer's site base URL
        $customerSite = Craft::$app->getSites()->getSiteById($customerRecord->siteId);
        $baseUrl = $customerSite?->getBaseUrl() ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        // Build full invitation URL with code
        $invitationUrl = rtrim($baseUrl, '/') . '/' . ltrim($invitationRoute, '/') . '?code=' . $customerRecord->smsInvitationCode;
        $shortenedUrl = $this->getBitlyUrl($invitationUrl);

        return Craft::$app->view->renderObjectTemplate(
            $message,
            $customerRecord,
            [
                'invitationUrl' => $shortenedUrl,
                'survey_link' => $shortenedUrl, // backwards compatibility
                'customer_name' => $customerRecord->name,
            ]
        );
    }

    /**
     * Shorten a URL using Bitly API
     */
    public function getBitlyUrl(string $surveyUrl): string
    {
        $apiv4 = 'https://api-ssl.bitly.com/v4/bitlinks';
        $genericAccessToken = App::env('BITLY_API_KEY');

        if (empty($genericAccessToken)) {
            $this->logWarning('Bitly API key not configured, returning original URL');
            return $surveyUrl;
        }

        $data = [
            'long_url' => $surveyUrl,
        ];
        $payload = json_encode($data);

        $header = [
            'Authorization: Bearer ' . $genericAccessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ];

        $ch = curl_init($apiv4);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logError('Bitly API curl error, returning original URL', ['error' => $curlError]);
            return $surveyUrl;
        }

        $resultToJson = json_decode($result);

        if (isset($resultToJson->link)) {
            $this->logInfo('Bitly URL created successfully');
            return $resultToJson->link;
        }

        $this->logError('Unable to create Bitly URL, returning original URL', ['response' => $result]);
        return $surveyUrl;
    }

    /**
     * Process and send an SMS invitation
     */
    public function processSmsInvitation(CampaignRecord $campaign, CustomerRecord $customer, ?int $senderIdId = null): bool
    {
        // Get content record for translatable SMS message
        $contentRecord = $campaign->getContentForSite($customer->siteId);
        $smsInvitationMessage = $contentRecord?->smsInvitationMessage;

        $result = $this->sendSmsInvitation($smsInvitationMessage, $customer, $senderIdId);
        if ($result) {
            $customer->smsSendDate = TimeHelper::now();
            $customer->save(false);
        }

        return $result;
    }

    /**
     * Mark a customer as having opened their invitation
     */
    public function markAsOpened(CustomerRecord $customer): void
    {
        $customer->smsOpenDate = TimeHelper::now();
        $customer->emailOpenDate = $customer->smsOpenDate;
        $customer->save(false);
    }

    /**
     * Process a form submission for a campaign
     */
    public function processCampaignSubmission(Submission $submission, string $invitationCode): void
    {
        $customer = $this->getCustomerByInvitationCode($invitationCode);

        if (!$customer) {
            $this->logWarning('Customer not found for invitation code', ['code' => $invitationCode]);
            return;
        }

        $submission->setFieldValue('customerName', $customer->name);
        $submission->setFieldValue('customerMobile', $customer->sms);
        $submission->setFieldValue('customerEmail', $customer->email);

        $campaign = $customer->getCampaign();
        if ($campaign) {
            $submission->setFieldValue('campaignName', $campaign->title);
        }

        $submission->updateTitle($submission->getForm());
        Craft::$app->getElements()->saveElement($submission, false);

        $customer->submissionId = $submission->getId();
        $customer->save(false);
    }

    /**
     * Send an SMS invitation
     */
    public function sendSmsInvitation(string $message, CustomerRecord $customer, ?int $senderIdId = null): bool
    {
        $parsedMessage = $this->parseInvitationMessageForCustomer($message, $customer);
        $language = $customer->getSite()?->getLocale()->getLanguageID() ?? 'en';

        return CampaignManager::$plugin->sms->sendSms(
            $customer->sms,
            $parsedMessage,
            $language,
            $senderIdId
        );
    }

    /**
     * Get the CP URL for surveys
     *
     * @param array<string, mixed> $params
     */
    public function getCpUrl(string $path, array $params = []): string
    {
        $surveysSection = Craft::$app->entries->getSectionByHandle('surveys');
        if ($surveysSection) {
            $params['source'] = 'section:' . $surveysSection->uid;
        }

        return UrlHelper::cpUrl($path, $params);
    }
}
