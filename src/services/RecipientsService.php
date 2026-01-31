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
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\TimeHelper;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Submission;
use yii\db\ActiveQuery;

/**
 * Recipients Service
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class RecipientsService extends Component
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
     * Get a recipient query
     *
     * @since 5.0.0
     */
    public function find(): ActiveQuery
    {
        return RecipientRecord::find();
    }

    /**
     * Find recipients by campaign and site
     *
     * @return RecipientRecord[]
     * @since 5.0.0
     */
    public function findByCampaignAndSite(int $campaignId, int $siteId, string $dateRange = 'all'): array
    {
        $query = RecipientRecord::find()
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

        /** @var RecipientRecord[] $result */
        $result = $query->all();

        return $result;
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     * @since 5.0.0
     */
    public function getDateRangeFromParam(string $dateRange): array
    {
        // Use centralized DateRangeHelper for full date range support
        // (today, yesterday, last7days, last30days, last90days, thisMonth, lastMonth, thisYear, lastYear, all)
        $bounds = DateRangeHelper::getBounds($dateRange);

        return [
            'start' => $bounds['start'] ?? new \DateTime('-30 days'),
            'end' => $bounds['end'] ?? new \DateTime(),
        ];
    }

    /**
     * Get a recipient by invitation code
     *
     * @since 5.0.0
     */
    public function getRecipientByInvitationCode(string $code): ?RecipientRecord
    {
        /** @var RecipientRecord|null $result */
        $result = RecipientRecord::find()
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
     *
     * @since 5.0.0
     */
    public function getUniqueInvitationCode(): string
    {
        do {
            $code = StringHelper::randomString(12);
            $recipient = RecipientRecord::find()
                ->where([
                    'or',
                    ['emailInvitationCode' => $code],
                    ['smsInvitationCode' => $code],
                ])
                ->one();
        } while (!empty($recipient));

        return $code;
    }

    /**
     * Delete a recipient by ID
     *
     * @since 5.0.0
     */
    public function deleteRecipientById(int $id): bool
    {
        return (bool)RecipientRecord::deleteAll(['id' => $id]);
    }

    /**
     * Parse an invitation message with recipient data
     *
     * @since 5.0.0
     */
    public function parseInvitationMessageForRecipient(string $message, RecipientRecord $recipientRecord): string
    {
        $campaign = $recipientRecord->getCampaign();

        // Build the invitation URL from the plugin's invitation route setting
        $settings = CampaignManager::$plugin->getSettings();
        $invitationRoute = $settings->invitationRoute ?? 'campaign-manager/invitation';

        // Get the recipient's site base URL
        $recipientSite = Craft::$app->getSites()->getSiteById($recipientRecord->siteId);
        $baseUrl = $recipientSite?->getBaseUrl() ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        // Build full invitation URL with code
        $invitationUrl = rtrim($baseUrl, '/') . '/' . ltrim($invitationRoute, '/') . '?code=' . $recipientRecord->smsInvitationCode;
        $shortenedUrl = $this->getBitlyUrl($invitationUrl);

        return Craft::$app->view->renderObjectTemplate(
            $message,
            $recipientRecord,
            [
                'invitationUrl' => $shortenedUrl,
                'survey_link' => $shortenedUrl, // backwards compatibility
                'recipient_name' => $recipientRecord->name,
            ]
        );
    }

    /**
     * Shorten a URL using Bitly API
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
     */
    public function processSmsInvitation(
        CampaignRecord $campaign,
        RecipientRecord $recipient,
        ?string $providerHandle = null,
        ?string $senderIdHandle = null,
    ): bool {
        // Get content record for translatable SMS message
        $contentRecord = $campaign->getContentForSite($recipient->siteId);
        $smsInvitationMessage = $contentRecord?->smsInvitationMessage;

        // Use campaign's provider/sender ID if not overridden
        $providerHandle = $providerHandle ?? $campaign->providerHandle;
        $senderIdHandle = $senderIdHandle ?? $campaign->senderId;

        $result = $this->sendSmsInvitation($smsInvitationMessage, $recipient, $providerHandle, $senderIdHandle);
        if ($result) {
            $recipient->smsSendDate = TimeHelper::now();
            $recipient->save(false);
        }

        return $result;
    }

    /**
     * Mark a recipient as having opened their invitation
     *
     * @since 5.0.0
     */
    public function markAsOpened(RecipientRecord $recipient): void
    {
        $recipient->smsOpenDate = TimeHelper::now();
        $recipient->emailOpenDate = $recipient->smsOpenDate;
        $recipient->save(false);
    }

    /**
     * Process a form submission for a campaign
     *
     * @since 5.0.0
     */
    public function processCampaignSubmission(Submission $submission, string $invitationCode): void
    {
        $recipient = $this->getRecipientByInvitationCode($invitationCode);

        if (!$recipient) {
            $this->logWarning('Recipient not found for invitation code', ['code' => $invitationCode]);
            return;
        }

        $submission->setFieldValue('recipientName', $recipient->name);
        $submission->setFieldValue('recipientMobile', $recipient->sms);
        $submission->setFieldValue('recipientEmail', $recipient->email);

        $campaign = $recipient->getCampaign();
        if ($campaign) {
            $submission->setFieldValue('campaignName', $campaign->title);
        }

        $submission->updateTitle($submission->getForm());
        Craft::$app->getElements()->saveElement($submission, false);

        $recipient->submissionId = $submission->getId();
        $recipient->save(false);
    }

    /**
     * Send an SMS invitation
     *
     * @since 5.0.0
     */
    public function sendSmsInvitation(
        string $message,
        RecipientRecord $recipient,
        ?string $providerHandle = null,
        ?string $senderIdHandle = null,
    ): bool {
        $parsedMessage = $this->parseInvitationMessageForRecipient($message, $recipient);
        $language = $recipient->getSite()?->getLocale()->getLanguageID() ?? 'en';

        return CampaignManager::$plugin->sms->sendSms(
            $recipient->sms,
            $parsedMessage,
            $language,
            $providerHandle,
            $senderIdHandle,
        );
    }

    /**
     * Get the CP URL for surveys
     *
     * @param array<string, mixed> $params
     * @since 5.0.0
     */
    public function getCpUrl(string $path, array $params = []): string
    {
        $surveysSection = Craft::$app->entries->getSectionByHandle('surveys');
        if ($surveysSection) {
            $params['source'] = 'section:' . $surveysSection->uid;
        }

        return UrlHelper::cpUrl($path, $params);
    }

    /**
     * Get recipients with form submissions for a campaign
     *
     * @param int $campaignId Campaign ID
     * @param int|null $siteId Site ID (null for all sites)
     * @param string $dateRange Date range filter (all, today, yesterday, last7days, last30days, last90days)
     * @return array<RecipientRecord> Recipients with submissions attached
     * @since 5.1.0
     */
    public function getWithSubmissions(int $campaignId, ?int $siteId = null, string $dateRange = 'all'): array
    {
        $query = RecipientRecord::find()
            ->where(['campaignId' => $campaignId])
            ->andWhere(['not', ['submissionId' => null]])
            ->orderBy(['dateUpdated' => SORT_DESC]);

        // Filter by site if specified
        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        /** @var RecipientRecord[] $recipients */
        $recipients = $query->all();

        // Calculate date range bounds
        $startDate = null;
        $endDate = new \DateTime();

        if ($dateRange !== 'all') {
            $dates = $this->getDateRangeFromParam($dateRange);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
        }

        // Eager load submissions and filter by date range
        $filteredRecipients = [];
        foreach ($recipients as $recipient) {
            if ($recipient->submissionId) {
                /** @var Submission|null $submission */
                $submission = Submission::find()->id($recipient->submissionId)->one();
                $recipient->submission = $submission;

                // Filter by date range if applicable
                if ($startDate !== null && $submission) {
                    $submissionDate = $submission->dateCreated;
                    if ($submissionDate >= $startDate && $submissionDate <= $endDate) {
                        $filteredRecipients[] = $recipient;
                    }
                } else {
                    $filteredRecipients[] = $recipient;
                }
            }
        }

        return $filteredRecipients;
    }
}
