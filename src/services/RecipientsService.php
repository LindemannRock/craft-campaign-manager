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
     */
    public function find(): ActiveQuery
    {
        return RecipientRecord::find();
    }

    /**
     * Find recipients by campaign and site
     *
     * @return RecipientRecord[]
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
            $bounds = DateRangeHelper::getBounds($dateRange);
            if ($bounds['start']) {
                $query->andWhere(['>=', 'dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['start'])]);
            }
            if ($bounds['end']) {
                $query->andWhere(['<', 'dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['end'])]);
            }
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
     */
    public function deleteRecipientById(int $id): bool
    {
        return (bool)RecipientRecord::deleteAll(['id' => $id]);
    }

    /**
     * Build the canonical set of template variables for an invitation
     * message rendered against a recipient. Used by both the SMS path
     * ({@see parseInvitationMessageForRecipient()}) and the email path
     * ({@see \lindemannrock\campaignmanager\services\EmailsService}) so
     * the same `{name}` / `{link}` / `{email}` placeholders work in
     * every messaging field on the campaign edit page (SMS body, email
     * subject, email body).
     *
     * The aliases exist because several historical conventions are now
     * in use across the plugin family:
     *
     *   - `{name}` / `{link}` — canonical, short, encouraged for new content.
     *   - `{recipientName}` — camelCase legacy used by earlier
     *     campaign-manager SMS templates.
     *   - `{recipient_name}` — snake_case, was email-path-only before this.
     *   - `{customer_name}` / `{survey_link}` — survey-campaigns naming;
     *     supported so admins migrating templates from survey-campaigns
     *     don't have to rewrite every placeholder.
     *   - `{invitationUrl}` — original verbose name, kept for back-compat
     *     with existing campaigns.
     *
     * Direct properties on `RecipientRecord` (`{email}`, `{sms}`, `{siteId}`,
     * etc.) also resolve through Craft's `renderObjectTemplate()` — no
     * need to enumerate them here.
     *
     * @return array<string, string>
     * @since 5.11.0
     */
    public function getInvitationTemplateVariables(RecipientRecord $recipient, string $invitationUrl): array
    {
        $name = (string) ($recipient->name ?? '');

        return [
            // Recipient name — all aliases resolve to the same value
            'name' => $name,
            'recipientName' => $name,
            'recipient_name' => $name,
            'customer_name' => $name,

            // Invitation URL — all aliases resolve to the same value
            'link' => $invitationUrl,
            'invitationUrl' => $invitationUrl,
            'survey_link' => $invitationUrl,

            // Convenience — direct property access also works via
            // renderObjectTemplate, but listing here makes intent clear
            // and survives any object-property-access edge cases.
            'email' => (string) ($recipient->email ?? ''),
        ];
    }

    /**
     * Parse an invitation message with recipient data
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

        try {
            return Craft::$app->view->renderObjectTemplate(
                $message,
                $recipientRecord,
                $this->getInvitationTemplateVariables($recipientRecord, $shortenedUrl)
            );
        } catch (\Throwable $e) {
            $this->logError('Failed to render invitation message template', [
                'campaignId' => $recipientRecord->campaignId,
                'recipientId' => $recipientRecord->id,
                'error' => $e->getMessage(),
            ]);

            return $message;
        }
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
     */
    public function markAsOpened(RecipientRecord $recipient): void
    {
        $recipient->smsOpenDate = TimeHelper::now();
        $recipient->emailOpenDate = $recipient->smsOpenDate;
        $recipient->save(false);
    }

    /**
     * Process a form submission for a campaign
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
            $recipient->siteId,
        );
    }

    /**
     * Get recipients with form submissions for a campaign
     *
     * Submissions are batch-loaded in a single query to avoid N+1. When no date
     * filter is active, pagination is applied at the SQL level for optimal performance.
     *
     * @param int $campaignId Campaign ID
     * @param int|null $siteId Site ID (null for all sites)
     * @param string $dateRange Date range filter (all, today, yesterday, last7days, last30days, last90days)
     * @param array<int>|null $editableSiteIds Restrict to these site IDs when siteId is null
     * @param int|null $limit Maximum number of recipients to return (null for no limit)
     * @param int|null $offset Number of matching recipients to skip before returning results
     * @return array<RecipientRecord> Recipients with submissions attached
     * @since 5.1.0
     */
    public function getWithSubmissions(
        int $campaignId,
        ?int $siteId = null,
        string $dateRange = 'all',
        ?array $editableSiteIds = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $query = $this->buildRecipientWithSubmissionQuery($campaignId, $siteId, $editableSiteIds);

        // Fast path: no date filter — paginate at SQL level
        if ($dateRange === 'all') {
            if ($limit !== null) {
                $query->limit($limit);
            }
            if ($offset !== null) {
                $query->offset($offset);
            }
            /** @var RecipientRecord[] $recipients */
            $recipients = $query->all();

            return $this->attachSubmissions($recipients);
        }

        // Date-filtered path: filter is on submission.dateCreated, so we must
        // load all candidates, attach submissions, then filter and paginate in PHP
        /** @var RecipientRecord[] $recipients */
        $recipients = $query->all();
        if (empty($recipients)) {
            return [];
        }

        $recipients = $this->attachSubmissions($recipients);

        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'];
        $endDate = $bounds['end'];

        $matched = 0;
        $filteredRecipients = [];
        foreach ($recipients as $recipient) {
            $submission = $recipient->submission;

            // Filter by submission date when both filter and submission are present.
            // Orphan recipients (submission missing) are kept to preserve visibility.
            if ($startDate !== null && $submission !== null) {
                $submissionDate = $submission->dateCreated;
                if ($endDate !== null) {
                    if ($submissionDate < $startDate || $submissionDate >= $endDate) {
                        continue;
                    }
                } elseif ($submissionDate < $startDate) {
                    continue;
                }
            }

            // Skip matches before $offset
            if ($offset !== null && $matched < $offset) {
                $matched++;
                continue;
            }
            $matched++;

            $filteredRecipients[] = $recipient;

            if ($limit !== null && count($filteredRecipients) >= $limit) {
                break;
            }
        }

        return $filteredRecipients;
    }

    /**
     * Count recipients with form submissions for a campaign
     *
     * Used for pagination totals. Without a date filter this runs as a single
     * COUNT(*) query. With a date filter it must apply the same filter logic
     * as getWithSubmissions() to stay consistent.
     *
     * @param int $campaignId Campaign ID
     * @param int|null $siteId Site ID (null for all sites)
     * @param string $dateRange Date range filter
     * @param array<int>|null $editableSiteIds Restrict to these site IDs when siteId is null
     * @since 5.10.0
     */
    public function countWithSubmissions(
        int $campaignId,
        ?int $siteId = null,
        string $dateRange = 'all',
        ?array $editableSiteIds = null,
    ): int {
        $query = $this->buildRecipientWithSubmissionQuery($campaignId, $siteId, $editableSiteIds);

        if ($dateRange === 'all') {
            return (int)$query->count();
        }

        // Date-filtered count must mirror getWithSubmissions() semantics —
        // delegate so the two stay in sync.
        return count($this->getWithSubmissions($campaignId, $siteId, $dateRange, $editableSiteIds));
    }

    /**
     * Build the base query for recipients with submissions.
     *
     * @param array<int>|null $editableSiteIds
     */
    private function buildRecipientWithSubmissionQuery(
        int $campaignId,
        ?int $siteId,
        ?array $editableSiteIds,
    ): ActiveQuery {
        $query = RecipientRecord::find()
            ->where(['campaignId' => $campaignId])
            ->andWhere(['not', ['submissionId' => null]])
            ->orderBy(['dateUpdated' => SORT_DESC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        } elseif ($editableSiteIds !== null) {
            $query->andWhere(['siteId' => $editableSiteIds]);
        }

        return $query;
    }

    /**
     * Attach Formie submissions to recipients in a single batch query.
     *
     * @param array<RecipientRecord> $recipients
     * @return array<RecipientRecord>
     */
    private function attachSubmissions(array $recipients): array
    {
        if (empty($recipients)) {
            return [];
        }

        $submissionIds = [];
        foreach ($recipients as $recipient) {
            if ($recipient->submissionId !== null) {
                $submissionIds[] = $recipient->submissionId;
            }
        }

        if (empty($submissionIds)) {
            return $recipients;
        }

        /** @var Submission[] $submissions */
        $submissions = Submission::find()->id($submissionIds)->all();
        $submissionsById = [];
        foreach ($submissions as $submission) {
            $submissionsById[$submission->id] = $submission;
        }

        foreach ($recipients as $recipient) {
            $recipient->submission = $submissionsById[$recipient->submissionId] ?? null;
        }

        return $recipients;
    }
}
