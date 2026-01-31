<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use lindemannrock\base\helpers\CsvImportHelper;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\PhoneHelper;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use verbb\formie\Formie;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Recipients Controller
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class RecipientsController extends Controller
{
    /**
     * Global recipient index (all recipients across all campaigns)
     *
     * @since 5.1.0
     */
    public function actionGlobalIndex(): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:viewRecipients');

        $settings = CampaignManager::$plugin->getSettings();

        // Get all campaigns for filter dropdown
        $campaigns = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->status(null)
            ->all();

        $campaignOptions = [];
        foreach ($campaigns as $campaign) {
            $campaignOptions[] = [
                'value' => $campaign->id,
                'label' => $campaign->title,
            ];
        }

        // Get all recipients
        $recipients = RecipientRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        return $this->renderTemplate('campaign-manager/recipients/index', [
            'recipients' => $recipients,
            'campaignOptions' => $campaignOptions,
            'settings' => $settings,
            'defaultDateRange' => DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id),
        ]);
    }

    /**
     * Export all recipients (global view)
     *
     * @throws BadRequestHttpException
     * @since 5.1.0
     */
    public function actionExportGlobal(): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:exportRecipients');
        $this->requirePermission('campaignManager:viewRecipients');

        $request = Craft::$app->getRequest();
        $format = $request->getQueryParam('format', 'csv');
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $campaignFilter = $request->getQueryParam('campaign', 'all');
        $siteFilter = $request->getQueryParam('siteFilter', 'all');
        $statusFilter = $request->getQueryParam('status', 'all');

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, CampaignManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        $query = RecipientRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC]);

        // Campaign filter
        if ($campaignFilter !== 'all') {
            $query->andWhere(['campaignId' => $campaignFilter]);
        }

        // Site filter
        if ($siteFilter !== 'all') {
            $query->andWhere(['siteId' => $siteFilter]);
        }

        // Status filter
        if ($statusFilter === 'pending') {
            $query->andWhere(['smsSendDate' => null])
                ->andWhere(['emailSendDate' => null]);
        } elseif ($statusFilter === 'sent') {
            $query->andWhere(['or',
                ['not', ['smsSendDate' => null]],
                ['not', ['emailSendDate' => null]],
            ]);
        }

        // Date range filter
        if ($dateRange !== 'all') {
            $dates = $this->getDateRangeFromParam($dateRange);
            $query->andWhere(['>=', 'dateCreated', $dates['start']->format('Y-m-d 00:00:00')])
                ->andWhere(['<=', 'dateCreated', $dates['end']->format('Y-m-d 23:59:59')]);
        }

        /** @var RecipientRecord[] $recipients */
        $recipients = $query->all();

        // Build export rows
        $rows = [];
        foreach ($recipients as $recipient) {
            // Get campaign name
            $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
                ->id($recipient->campaignId)
                ->status(null)
                ->one();

            // Get site name
            $site = Craft::$app->getSites()->getSiteById($recipient->siteId);

            // Determine status
            $status = ($recipient->smsSendDate || $recipient->emailSendDate) ? 'Sent' : 'Pending';

            $rows[] = [
                'name' => $recipient->name,
                'email' => $recipient->email,
                'sms' => $recipient->sms,
                'campaign' => $campaign?->title ?? 'Unknown',
                'site' => $site?->name ?? 'Unknown',
                'status' => $status,
                'emailSendDate' => $recipient->emailSendDate,
                'smsSendDate' => $recipient->smsSendDate,
                'emailOpenDate' => $recipient->emailOpenDate,
                'smsOpenDate' => $recipient->smsOpenDate,
                'submissionId' => $recipient->submissionId,
                'dateCreated' => $recipient->dateCreated,
            ];
        }

        // Check for empty data
        if (empty($rows)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No recipients to export for the selected filters.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        $headers = [
            'Name',
            'Email',
            'SMS',
            'Campaign',
            'Site',
            'Status',
            'Email Sent',
            'SMS Sent',
            'Email Opened',
            'SMS Opened',
            'Submission ID',
            'Date Created',
        ];

        // Build filename
        $settings = CampaignManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = $format === 'xlsx' ? 'xlsx' : $format;
        $filename = ExportHelper::filename($settings, ['recipients', $dateRangeLabel], $extension);

        $dateColumns = ['emailSendDate', 'smsSendDate', 'emailOpenDate', 'smsOpenDate', 'dateCreated'];

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename, $dateColumns),
            'json' => ExportHelper::toJson($rows, $filename, $dateColumns),
            'xlsx', 'excel' => ExportHelper::toExcel($rows, $headers, $filename, $dateColumns, [
                'sheetTitle' => 'Recipients',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }

    /**
     * Export responses (recipients with form submissions)
     *
     * @throws BadRequestHttpException
     * @since 5.1.0
     */
    public function actionExportResponses(): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:exportRecipients');
        $this->requirePermission('campaignManager:viewRecipients');

        $request = Craft::$app->getRequest();
        $format = $request->getQueryParam('format', 'csv');
        $campaignId = (int)$request->getQueryParam('campaignId');
        $siteFilter = $request->getQueryParam('siteFilter', 'all');
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));

        if (!$campaignId) {
            throw new BadRequestHttpException('Campaign ID is required.');
        }

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, CampaignManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        // Get recipients with submissions (filtered by site if specified)
        $filterSiteId = $siteFilter !== 'all' ? (int)$siteFilter : null;
        $recipients = CampaignManager::$plugin->recipients->getWithSubmissions($campaignId, $filterSiteId, $dateRange);

        // Check for empty data
        if (empty($recipients)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No responses to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Get the campaign and form (use primary site to get the form)
        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new BadRequestHttpException('Campaign not found.');
        }

        // Get the Formie form and its fields
        $form = Formie::$plugin->getForms()->getFormById($campaign->formId);
        $formFields = $form ? $form->getCustomFields() : [];

        // Filter to only include displayable field types
        $displayableFields = [];
        $excludedTypes = [
            'verbb\\formie\\fields\\formfields\\Section',
            'verbb\\formie\\fields\\formfields\\Html',
            'verbb\\formie\\fields\\formfields\\Hidden',
            'verbb\\formie\\fields\\formfields\\Heading',
            'verbb\\formie\\fields\\Group',
            'verbb\\formie\\fields\\Repeater',
        ];

        foreach ($formFields as $field) {
            $fieldClass = get_class($field);
            if (!in_array($fieldClass, $excludedTypes)) {
                $displayableFields[] = $field;
            }
        }

        // Build headers
        $headers = ['Name', 'Email', 'Phone', 'Site', 'Submitted'];
        foreach ($displayableFields as $field) {
            $headers[] = $field->label;
        }

        // Build rows
        $rows = [];
        foreach ($recipients as $recipient) {
            $submission = $recipient->submission;
            $recipientSite = Craft::$app->getSites()->getSiteById($recipient->siteId);

            $row = [
                'name' => $recipient->name ?? '-',
                'email' => $recipient->email ?? '-',
                'phone' => $recipient->sms ?? '-',
                'site' => $recipientSite?->name ?? '-',
                'submitted' => $submission ? $submission->dateCreated : null,
            ];

            // Add form field values
            foreach ($displayableFields as $field) {
                if ($submission) {
                    $fieldValue = $submission->getFieldValue($field->handle);
                    if (is_array($fieldValue)) {
                        $row[$field->handle] = implode(', ', $fieldValue);
                    } else {
                        $row[$field->handle] = $fieldValue ?? '-';
                    }
                } else {
                    $row[$field->handle] = '-';
                }
            }

            $rows[] = $row;
        }

        // Build filename
        $settings = CampaignManager::$plugin->getSettings();
        $extension = $format === 'xlsx' ? 'xlsx' : $format;
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = ExportHelper::filename($settings, ['responses', 'campaign-' . $campaignId, $dateRangeLabel], $extension);

        $dateColumns = ['submitted'];

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename, $dateColumns),
            'json' => ExportHelper::toJson($rows, $filename, $dateColumns),
            'xlsx', 'excel' => ExportHelper::toExcel($rows, $headers, $filename, $dateColumns, [
                'sheetTitle' => 'Responses',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }

    /**
     * Recipient index for a campaign
     *
     * @since 5.0.0
     */
    public function actionIndex(int $campaignId): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:viewRecipients');

        $siteHandle = Craft::$app->getRequest()->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new \yii\web\NotFoundHttpException('Campaign not found');
        }

        return $this->renderTemplate('campaign-manager/recipients/list', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
            'defaultDateRange' => DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id),
        ]);
    }

    /**
     * Add recipient form
     *
     * @since 5.0.0
     */
    public function actionAddForm(int $campaignId): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:addRecipients');

        $siteHandle = Craft::$app->getRequest()->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new \yii\web\NotFoundHttpException('Campaign not found');
        }

        return $this->renderTemplate('campaign-manager/recipients/add', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
        ]);
    }

    /**
     * Import recipients form
     *
     * @since 5.0.0
     */
    public function actionImportForm(int $campaignId): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');

        $siteHandle = Craft::$app->getRequest()->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new \yii\web\NotFoundHttpException('Campaign not found');
        }

        return $this->renderTemplate('campaign-manager/recipients/import', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
            'importLimits' => [
                'maxRows' => CsvImportHelper::DEFAULT_MAX_ROWS,
                'maxBytes' => CsvImportHelper::DEFAULT_MAX_BYTES,
            ],
        ]);
    }

    /**
     * Add a recipient
     *
     * @since 5.0.0
     */
    public function actionAdd(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:addRecipients');

        // Check if campaign is enabled
        $campaignId = (int)$this->request->getRequiredParam('campaignId');
        $siteId = (int)$this->request->getRequiredParam('siteId');

        // Load campaign for the specific site being added to
        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$campaign || !$campaign->getEnabledForSite()) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Cannot add recipients to a disabled campaign.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        $sms = $this->request->getParam('sms');
        $smsCountry = $this->request->getParam('smsCountry');
        $email = $this->request->getParam('email');

        // Create recipient record with submitted data first (for form re-population)
        $recipient = new RecipientRecord([
            'campaignId' => $campaignId,
            'siteId' => $siteId,
            'name' => $this->request->getParam('name'),
            'email' => $email,
            'sms' => $sms,
        ]);

        // Validate name is provided
        if (empty($recipient->name)) {
            $recipient->addError('name', Craft::t('campaign-manager', 'Name is required.'));
        }

        // Validate email format (if provided)
        $emailValid = true;
        if ($email !== null && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipient->addError('email', Craft::t('campaign-manager', 'Invalid email address.'));
                $emailValid = false;
            }
        }

        // Validate and sanitize phone number with explicit country (if provided)
        $smsValid = true;
        if ($sms !== null && $sms !== '') {
            // Validate country is provided
            if (empty($smsCountry)) {
                $recipient->addError('sms', Craft::t('campaign-manager', 'Please select a phone country.'));
                $smsValid = false;
            } else {
                // Verify the country is allowed for this campaign's provider
                $settings = CampaignManager::$plugin->getSettings();
                $providerHandle = $campaign->providerHandle ?? $settings->defaultProviderHandle;
                $countryAllowed = true;

                if ($providerHandle) {
                    $allowedCountries = CampaignManager::$plugin->sms->getAllowedCountries($providerHandle);
                    $isAllCountries = $allowedCountries === ['*'];

                    if (!$isAllCountries && !in_array($smsCountry, $allowedCountries, true)) {
                        $recipient->addError('sms', Craft::t('campaign-manager', 'Country {country} is not allowed for this campaign\'s SMS provider.', ['country' => $smsCountry]));
                        $smsValid = false;
                        $countryAllowed = false;
                    }
                }

                // Validate phone with explicit country code (only if country is allowed)
                if ($countryAllowed) {
                    $phoneValidation = PhoneHelper::validateWithCountry($sms, $smsCountry);
                    if (!$phoneValidation['valid']) {
                        $recipient->addError('sms', $phoneValidation['error'] ?? Craft::t('campaign-manager', 'Invalid phone number.'));
                        $smsValid = false;
                    } else {
                        $recipient->sms = $phoneValidation['e164'];
                    }
                }
            }
        }

        // Must have at least one valid contact method (email or SMS)
        $hasValidEmail = $email !== null && $email !== '' && $emailValid;
        $hasValidSms = $sms !== null && $sms !== '' && $smsValid;

        if (empty($email) && empty($sms)) {
            // Neither provided
            $recipient->addError('email', Craft::t('campaign-manager', 'Email or phone number is required.'));
        } elseif (!$hasValidEmail && !$hasValidSms) {
            // Both provided but both invalid - errors already added above
            // No additional error needed
        }

        $hasErrors = $recipient->hasErrors();

        if ($hasErrors) {
            return $this->renderAddRecipientFormWithErrors($recipient, Craft::t('campaign-manager', 'Couldn\'t add recipient.'));
        }

        if (!$recipient->save()) {
            return $this->renderAddRecipientFormWithErrors($recipient, Craft::t('campaign-manager', 'Could not save recipient.'));
        }

        // Queue invitation if requested
        $sendInvitation = $this->request->getBodyParam('sendInvitation');
        if ($sendInvitation) {
            $campaign = CampaignRecord::findOneForSite($recipient->campaignId, $recipient->siteId);
            if ($campaign) {
                Craft::$app->getQueue()->push(new SendBatchJob([
                    'campaignId' => $recipient->campaignId,
                    'siteId' => $recipient->siteId,
                    'recipientIds' => [$recipient->id],
                    'sendSms' => !empty($recipient->sms),
                    'sendEmail' => !empty($recipient->email),
                ]));
            }
        }

        return $this->returnSuccessResponse($recipient);
    }

    /**
     * Download sample CSV
     *
     * @since 5.0.0
     */
    public function actionDownloadSample(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');
        $templatePath = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'recipients-import-template.csv';

        return Craft::$app->getResponse()->sendFile($templatePath);
    }

    /**
     * Delete a recipient from the CP
     *
     * @since 5.0.0
     */
    public function actionDeleteFromCp(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:deleteRecipients');

        $recipientId = (int)Craft::$app->request->getRequiredBodyParam('id');

        if (!CampaignManager::$plugin->recipients->deleteRecipientById($recipientId)) {
            return $this->asJson(null);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Bulk delete recipients
     *
     * @since 5.1.0
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:deleteRecipients');

        $recipientIds = Craft::$app->request->getRequiredBodyParam('recipientIds');
        $count = 0;
        $errors = [];

        foreach ($recipientIds as $recipientId) {
            if (CampaignManager::$plugin->recipients->deleteRecipientById((int)$recipientId)) {
                $count++;
            } else {
                $errors[] = Craft::t('campaign-manager', 'Failed to delete recipient {id}', ['id' => $recipientId]);
            }
        }

        return $this->asJson([
            'success' => $count > 0,
            'count' => $count,
            'errors' => $errors,
        ]);
    }

    /**
     * Delete a recipient
     *
     * @since 5.0.0
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:deleteRecipients');

        $recipientId = (int)Craft::$app->request->getRequiredBodyParam('id');

        if (!CampaignManager::$plugin->recipients->deleteRecipientById($recipientId)) {
            return $this->asJson(null);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Upload and parse CSV file (step 1 of import)
     *
     * @since 5.1.0
     */
    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');

        $file = UploadedFile::getInstanceByName('csvFile');
        $campaignId = (int)$this->request->getRequiredParam('campaignId');

        // Check if campaign is enabled for at least one site
        $campaignEnabledForAnySite = false;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $campaignForSite = \lindemannrock\campaignmanager\elements\Campaign::find()
                ->id($campaignId)
                ->siteId($site->id)
                ->status(null)
                ->one();
            if ($campaignForSite && $campaignForSite->getEnabledForSite()) {
                $campaignEnabledForAnySite = true;
                break;
            }
        }

        if (!$campaignEnabledForAnySite) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Cannot import recipients to a disabled campaign.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/recipients");
        }

        if (!$file) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Please select a CSV file to upload'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        $queueSending = (bool)$this->request->getBodyParam('queueSending', true);

        // Get delimiter (auto-detect by default)
        $delimiter = $this->request->getBodyParam('delimiter', 'auto');
        $detectDelimiter = true;
        if ($delimiter !== 'auto') {
            if ($delimiter === "\t") {
                $delimiter = "\t"; // Handle tab character
            }
            $detectDelimiter = false;
        } else {
            $delimiter = null;
        }

        try {
            $parsed = CsvImportHelper::parseUpload($file, [
                'maxRows' => CsvImportHelper::DEFAULT_MAX_ROWS,
                'maxBytes' => CsvImportHelper::DEFAULT_MAX_BYTES,
                'delimiter' => $delimiter,
                'detectDelimiter' => $detectDelimiter,
            ]);

            // Store parsed data in session
            Craft::$app->getSession()->set('recipient-import', [
                'headers' => $parsed['headers'],
                'allRows' => $parsed['allRows'],
                'rowCount' => $parsed['rowCount'],
                'campaignId' => $campaignId,
                'queueSending' => $queueSending,
            ]);

            // Redirect to column mapping
            $siteHandle = $this->request->getParam('site', 'en');
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/map-recipients?site={$siteHandle}");
        } catch (\Exception $e) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Failed to parse CSV: {error}', ['error' => $e->getMessage()]));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }
    }

    /**
     * Map CSV columns (step 2 of import)
     *
     * @since 5.1.0
     */
    public function actionMap(int $campaignId): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');

        $siteHandle = Craft::$app->getRequest()->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new \yii\web\NotFoundHttpException('Campaign not found');
        }

        // Get data from session
        $importData = Craft::$app->getSession()->get('recipient-import');

        if (!$importData || !isset($importData['allRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No import data found. Please upload a CSV file.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        // Verify campaign ID matches
        if ($importData['campaignId'] !== $campaignId) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Campaign mismatch. Please upload the CSV file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        // Get first 5 rows for preview
        $previewRows = array_slice($importData['allRows'], 0, 5);

        return $this->renderTemplate('campaign-manager/recipients/map', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
            'headers' => $importData['headers'],
            'previewRows' => $previewRows,
            'rowCount' => $importData['rowCount'],
            'queueSending' => $importData['queueSending'],
        ]);
    }

    /**
     * Preview import (step 3 of import - validates and shows preview)
     *
     * @since 5.1.0
     */
    public function actionPreview(): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');

        // Handle GET request (page refresh) - check for existing preview data
        if ($this->request->getIsGet()) {
            $previewData = Craft::$app->getSession()->get('recipient-import-preview');

            if ($previewData && isset($previewData['validRows'])) {
                // Re-fetch campaign and site objects (don't store in session)
                $campaignId = $previewData['campaignId'];
                $siteId = $previewData['siteId'];
                $site = Craft::$app->getSites()->getSiteById($siteId);
                $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
                    ->id($campaignId)
                    ->siteId($siteId)
                    ->status(null)
                    ->one();

                if ($campaign && $site) {
                    return $this->renderTemplate('campaign-manager/recipients/preview', [
                        'campaign' => $campaign,
                        'campaignId' => $campaignId,
                        'site' => $site,
                        'summary' => $previewData['summary'],
                        'validRows' => $previewData['validRows'],
                        'duplicateRows' => $previewData['duplicateRows'],
                        'errorRows' => $previewData['errorRows'],
                        'queueSending' => $previewData['queueSending'],
                    ]);
                }
            }

            // No preview data - redirect to import page
            $campaignId = $this->request->getParam('campaignId');
            if ($campaignId) {
                Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Preview session expired. Please upload the file again.'));
                return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
            }

            // Fallback to main plugin page
            return $this->redirect('campaign-manager');
        }

        $campaignId = (int)$this->request->getRequiredParam('campaignId');
        $queueSending = (bool)$this->request->getParam('queueSending', true);
        $mapping = $this->request->getBodyParam('mapping', []);

        // Get site and campaign
        $siteHandle = $this->request->getParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($campaignId)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new \yii\web\NotFoundHttpException('Campaign not found');
        }

        // Get data from session
        $importData = Craft::$app->getSession()->get('recipient-import');

        if (!$importData || !isset($importData['allRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Import session expired. Please upload the file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        // Create reverse mapping (column index => field name)
        $columnMap = [];
        foreach ($mapping as $colIndex => $fieldName) {
            if (!empty($fieldName)) {
                $columnMap[(int)$colIndex] = $fieldName;
            }
        }

        // Validate required fields are mapped
        $mappedFields = array_values($columnMap);
        $errors = [];

        if (!in_array('name', $mappedFields)) {
            $errors[] = 'Name';
        }

        if (!in_array('email', $mappedFields) && !in_array('sms', $mappedFields)) {
            $errors[] = 'Email or Phone';
        }

        if (!empty($errors)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Required fields not mapped: {fields}', ['fields' => implode(', ', $errors)]));
            $siteHandle = $this->request->getParam('site', 'en');
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/map-recipients?site={$siteHandle}");
        }

        // Determine default site ID from form
        $defaultSiteId = (int)$this->request->getBodyParam('defaultSiteId', 1);
        $hasSiteMapping = in_array('site', $mappedFields);

        // Get phone country for validation
        $phoneCountry = $this->request->getBodyParam('phoneCountry', '');

        // Get allowed countries for validation
        $settings = CampaignManager::$plugin->getSettings();
        $providerHandle = $campaign->providerHandle ?? $settings->defaultProviderHandle;
        $allowedCountries = $providerHandle ? CampaignManager::$plugin->sms->getAllowedCountries($providerHandle) : [];
        $isAllCountries = $allowedCountries === ['*'];

        // Build dial code to country mapping for auto-detection
        $dialCodeToCountry = [];
        if (!empty($allowedCountries) && !$isAllCountries) {
            foreach ($allowedCountries as $code) {
                $dialCode = \lindemannrock\base\helpers\GeoHelper::getDialCode($code);
                if ($dialCode) {
                    $dialCodeToCountry[$dialCode] = $code;
                }
            }
        }
        // Sort by dial code length descending (longer codes first)
        uksort($dialCodeToCountry, fn($a, $b) => strlen($b) - strlen($a));

        // Track duplicates within this CSV batch
        $batchPhoneKeys = [];
        $batchEmailKeys = [];

        // Process rows and categorize them
        $validRows = [];
        $duplicateRows = [];
        $errorRows = [];
        $rowNumber = 0;

        foreach ($importData['allRows'] as $row) {
            $rowNumber++;

            // Map CSV row to recipient fields
            $recipientData = [
                'name' => null,
                'email' => null,
                'sms' => null,
                'site' => '',
            ];

            foreach ($columnMap as $colIndex => $fieldName) {
                if (isset($row[$colIndex])) {
                    $value = trim($row[$colIndex]);
                    if ($value !== '') {
                        $recipientData[$fieldName] = $value;
                    }
                }
            }

            // Check for missing name
            if (empty($recipientData['name'])) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $recipientData['name'] ?? '-',
                    'error' => Craft::t('campaign-manager', 'Missing required field: Name'),
                ];
                continue;
            }

            // Check for missing contact method
            if (empty($recipientData['email']) && empty($recipientData['sms'])) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $recipientData['name'],
                    'error' => Craft::t('campaign-manager', 'Missing required field: Email or Phone'),
                ];
                continue;
            }

            // Determine site ID from site column or use fallback
            $siteValue = $hasSiteMapping ? strtolower(trim($recipientData['site'] ?? '')) : '';
            $siteId = $defaultSiteId;

            if ($siteValue !== '') {
                // Try to find site by handle first
                $matchedSite = Craft::$app->getSites()->getSiteByHandle($siteValue);

                // If not found by handle, try by ID
                if ($matchedSite === null && is_numeric($siteValue)) {
                    $matchedSite = Craft::$app->getSites()->getSiteById((int)$siteValue);
                }

                if ($matchedSite !== null) {
                    $siteId = $matchedSite->id;
                }
            }

            // Get site for display
            $siteForDisplay = Craft::$app->getSites()->getSiteById($siteId);

            // Validate and sanitize phone number
            $sms = $recipientData['sms'];
            if ($sms !== null && $sms !== '') {
                // Sanitize the phone number first
                $cleanedSms = PhoneHelper::sanitize($sms);

                if ($cleanedSms === null || $cleanedSms === '') {
                    $errorRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $recipientData['name'],
                        'error' => Craft::t('campaign-manager', 'Phone number is empty after sanitization'),
                    ];
                    continue;
                }

                // Determine the country to use for validation
                $detectedCountry = null;
                $numberToValidate = $cleanedSms;

                // Strip + or 00 prefix for detection
                $digitsOnly = preg_replace('/[^0-9]/', '', $cleanedSms);

                // Try to auto-detect country from dial code
                foreach ($dialCodeToCountry as $dialCode => $country) {
                    if (str_starts_with($digitsOnly, $dialCode)) {
                        $detectedCountry = $country;
                        break;
                    }
                }

                // Use detected country or fall back to selected phone country
                $countryForValidation = $detectedCountry ?? $phoneCountry;

                if (empty($countryForValidation)) {
                    $errorRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $recipientData['name'],
                        'error' => Craft::t('campaign-manager', 'No phone country configured'),
                    ];
                    continue;
                }

                // Validate with the determined country
                $phoneValidation = PhoneHelper::validateWithCountry($cleanedSms, $countryForValidation);
                if (!$phoneValidation['valid']) {
                    $errorRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $recipientData['name'],
                        'error' => $phoneValidation['error'] ?? Craft::t('campaign-manager', 'Invalid phone number'),
                    ];
                    continue;
                }

                // Verify the validated number's country is in allowed list
                if (!$isAllCountries && !empty($allowedCountries)) {
                    $validatedCountry = $phoneValidation['country'];
                    if ($validatedCountry && !in_array($validatedCountry, $allowedCountries, true)) {
                        $errorRows[] = [
                            'rowNumber' => $rowNumber,
                            'name' => $recipientData['name'],
                            'error' => Craft::t('campaign-manager', 'Phone country {country} not allowed for this provider', ['country' => $validatedCountry]),
                        ];
                        continue;
                    }
                }

                $sms = $phoneValidation['e164'];
            }

            // Validate email format
            $email = !empty($recipientData['email']) ? strtolower(trim($recipientData['email'])) : null;
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $recipientData['name'],
                    'error' => Craft::t('campaign-manager', 'Invalid email address: {email}', ['email' => $email]),
                ];
                continue;
            }

            // If we have no valid phone and no valid email, skip
            $hasValidSms = $sms !== null && $sms !== '';
            $hasValidEmail = $email !== null;
            if (!$hasValidSms && !$hasValidEmail) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $recipientData['name'],
                    'error' => Craft::t('campaign-manager', 'No valid contact method (email or phone)'),
                ];
                continue;
            }

            // Check for duplicates within CSV by phone number (same site)
            if (!empty($sms)) {
                $phoneKey = $siteId . '|' . strtolower($sms);

                if (isset($batchPhoneKeys[$phoneKey])) {
                    $duplicateRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $recipientData['name'],
                        'identifier' => $sms,
                        'reason' => Craft::t('campaign-manager', 'Same phone as row {row}', ['row' => $batchPhoneKeys[$phoneKey]]),
                    ];
                    continue;
                }

                $batchPhoneKeys[$phoneKey] = $rowNumber;
            }

            // Check for duplicates within CSV by email (same site) - only if no phone
            if (empty($sms) && !empty($email)) {
                $emailKey = $siteId . '|' . $email;

                if (isset($batchEmailKeys[$emailKey])) {
                    $duplicateRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $recipientData['name'],
                        'identifier' => $email,
                        'reason' => Craft::t('campaign-manager', 'Same email as row {row}', ['row' => $batchEmailKeys[$emailKey]]),
                    ];
                    continue;
                }

                $batchEmailKeys[$emailKey] = $rowNumber;
            }

            // Row is valid - add to valid rows
            $validRows[] = [
                'name' => $recipientData['name'],
                'email' => $email,
                'sms' => $sms,
                'siteId' => $siteId,
                'siteName' => $siteForDisplay?->name ?? '',
            ];
        }

        // Build summary
        $summary = [
            'totalRows' => count($importData['allRows']),
            'validRows' => count($validRows),
            'duplicates' => count($duplicateRows),
            'errors' => count($errorRows),
        ];

        // Store validated data in session for import step (only serializable data, no objects)
        Craft::$app->getSession()->set('recipient-import-preview', [
            'validRows' => $validRows,
            'campaignId' => $campaignId,
            'siteId' => $site->id,
            'queueSending' => $queueSending,
            'summary' => $summary,
            'duplicateRows' => $duplicateRows,
            'errorRows' => $errorRows,
        ]);

        return $this->renderTemplate('campaign-manager/recipients/preview', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
            'summary' => $summary,
            'validRows' => $validRows,
            'duplicateRows' => $duplicateRows,
            'errorRows' => $errorRows,
            'queueSending' => $queueSending,
        ]);
    }

    /**
     * Import recipients from preview (step 4 of import - actual import)
     *
     * @since 5.1.0
     */
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:importRecipients');

        $campaignId = (int)$this->request->getRequiredParam('campaignId');

        // Check if campaign is enabled for at least one site
        $campaignEnabledForAnySite = false;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $campaignForSite = \lindemannrock\campaignmanager\elements\Campaign::find()
                ->id($campaignId)
                ->siteId($site->id)
                ->status(null)
                ->one();
            if ($campaignForSite && $campaignForSite->getEnabledForSite()) {
                $campaignEnabledForAnySite = true;
                break;
            }
        }

        if (!$campaignEnabledForAnySite) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Cannot import recipients to a disabled campaign.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/recipients");
        }

        // Get validated data from preview session
        $previewData = Craft::$app->getSession()->get('recipient-import-preview');

        if (!$previewData || !isset($previewData['validRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Import session expired. Please upload the file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        // Verify campaign ID matches
        if ($previewData['campaignId'] !== $campaignId) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Campaign mismatch. Please upload the CSV file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-recipients");
        }

        $queueSending = $previewData['queueSending'];
        $validRows = $previewData['validRows'];

        // Import validated rows
        $imported = 0;
        $failed = 0;
        $errorMessages = [];

        foreach ($validRows as $index => $rowData) {
            $recipient = new RecipientRecord([
                'campaignId' => $campaignId,
                'siteId' => $rowData['siteId'],
                'name' => $rowData['name'],
                'email' => $rowData['email'],
                'sms' => $rowData['sms'],
            ]);

            try {
                if ($recipient->save()) {
                    $imported++;
                } else {
                    $failed++;
                    $errorMessages[] = "Row " . ($index + 1) . ": " . implode(', ', $recipient->getErrorSummary(true));
                }
            } catch (\Exception $e) {
                $failed++;
                $errorMessages[] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        // Clean up session data
        Craft::$app->getSession()->remove('recipient-import');
        Craft::$app->getSession()->remove('recipient-import-preview');

        // Build result message
        $message = Craft::t('campaign-manager', 'Successfully imported {imported} recipient(s).', ['imported' => $imported]);
        if ($failed > 0) {
            $message .= ' ' . Craft::t('campaign-manager', '{failed} failed.', ['failed' => $failed]);
        }

        // Queue sending if requested and we imported recipients
        if ($queueSending && $imported > 0) {
            // Get unique site IDs from imported recipients
            $siteIds = RecipientRecord::find()
                ->select(['siteId'])
                ->where(['campaignId' => $campaignId])
                ->andWhere(['smsSendDate' => null])
                ->andWhere(['emailSendDate' => null])
                ->distinct()
                ->column();

            foreach ($siteIds as $siteId) {
                Craft::$app->getQueue()->push(new \lindemannrock\campaignmanager\jobs\ProcessCampaignJob([
                    'campaignId' => $campaignId,
                    'siteId' => (int)$siteId,
                    'sendSms' => true,
                    'sendEmail' => true,
                ]));
            }

            $message .= ' ' . Craft::t('campaign-manager', 'Invitation sending has been queued.');
        }

        if ($failed > 0 && count($errorMessages) <= 10) {
            Craft::warning('Recipient import errors: ' . implode('; ', $errorMessages), 'campaign-manager');
        }

        Craft::$app->getSession()->setNotice($message);

        $siteHandle = $this->request->getParam('site', 'en');
        return $this->redirect("campaign-manager/campaigns/{$campaignId}/recipients?site={$siteHandle}");
    }

    /**
     * Export recipients
     *
     * @throws BadRequestHttpException
     * @since 5.0.0
     */
    public function actionExportRecipients(int $campaignId): Response
    {
        $this->requireLogin();
        $this->requirePermission('campaignManager:exportRecipients');
        $this->requirePermission('campaignManager:viewRecipients');

        $request = Craft::$app->getRequest();
        $siteHandle = $request->getQueryParam('site');
        $site = $siteHandle ? Craft::$app->getSites()->getSiteByHandle($siteHandle) : Craft::$app->getSites()->getPrimarySite();
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(CampaignManager::$plugin->id));
        $format = $request->getQueryParam('format', 'csv');

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, CampaignManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $query = RecipientRecord::find()
            ->where([
                'campaignId' => $campaignId,
                'siteId' => $site->id,
            ])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($dateRange !== 'all') {
            $query->andWhere(['>=', 'dateCreated', $startDate->format('Y-m-d 00:00:00')])
                ->andWhere(['<=', 'dateCreated', $endDate->format('Y-m-d 23:59:59')]);
        }

        /** @var RecipientRecord[] $recipients */
        $recipients = $query->all();

        // Check for empty data
        if (empty($recipients)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No recipients to export.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        // Build rows
        $rows = [];
        foreach ($recipients as $recipient) {
            $rows[] = [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'phone' => $recipient->sms,
                'emailSendDate' => $recipient->emailSendDate,
                'smsSendDate' => $recipient->smsSendDate,
                'emailOpenDate' => $recipient->emailOpenDate,
                'smsOpenDate' => $recipient->smsOpenDate,
                'submissionId' => $recipient->submissionId,
                'dateCreated' => $recipient->dateCreated,
            ];
        }

        $headers = [
            Craft::t('campaign-manager', 'ID'),
            Craft::t('campaign-manager', 'Name'),
            Craft::t('campaign-manager', 'Email'),
            Craft::t('campaign-manager', 'Phone'),
            Craft::t('campaign-manager', 'Email Sent Date'),
            Craft::t('campaign-manager', 'SMS Sent Date'),
            Craft::t('campaign-manager', 'Email Opened Date'),
            Craft::t('campaign-manager', 'SMS Opened Date'),
            Craft::t('campaign-manager', 'Submission ID'),
            Craft::t('campaign-manager', 'Date Created'),
        ];

        // Build filename
        $settings = CampaignManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = $format === 'xlsx' ? 'xlsx' : $format;
        $filename = ExportHelper::filename($settings, ['recipients', 'campaign-' . $campaignId, $dateRangeLabel], $extension);

        $dateColumns = ['emailSendDate', 'smsSendDate', 'emailOpenDate', 'smsOpenDate', 'dateCreated'];

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename, $dateColumns),
            'json' => ExportHelper::toJson($rows, $filename, $dateColumns),
            'xlsx', 'excel' => ExportHelper::toExcel($rows, $headers, $filename, $dateColumns, [
                'sheetTitle' => 'Recipients',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     */
    private function getDateRangeFromParam(string $dateRange): array
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
     * Render the add recipient form with errors
     */
    protected function renderAddRecipientFormWithErrors(RecipientRecord $recipient, ?string $errorMessage = null): Response
    {
        if ($errorMessage) {
            Craft::$app->getSession()->setError($errorMessage);
        }

        $siteId = $recipient->siteId;
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($recipient->campaignId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $this->renderTemplate('campaign-manager/recipients/add', [
            'campaign' => $campaign,
            'campaignId' => $recipient->campaignId,
            'site' => $site,
            'recipient' => $recipient,
        ]);
    }

    /**
     * Return an error response
     *
     * @param array<string, mixed> $routeParams
     */
    protected function returnErrorResponse(string $errorMessage, array $routeParams = []): ?Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['error' => $errorMessage]);
        }

        Craft::$app->getSession()->setError($errorMessage);

        Craft::$app->getUrlManager()->setRouteParams([
            'errorMessage' => $errorMessage,
        ] + $routeParams);

        return null;
    }

    /**
     * Return a success response
     */
    protected function returnSuccessResponse(mixed $returnUrlObject = null): Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirectToPostedUrl($returnUrlObject, Craft::$app->getRequest()->getReferrer());
    }
}
