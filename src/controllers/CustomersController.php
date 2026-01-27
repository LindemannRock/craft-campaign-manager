<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\db\ActiveQuery;
use craft\web\Controller;
use craft\web\UploadedFile;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\PhoneHelper;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\CustomerRecord;
use verbb\formie\Formie;
use yii\data\Pagination;
use yii\web\Response;

/**
 * Customers Controller
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class CustomersController extends Controller
{
    /**
     * Customer index for a campaign
     */
    public function actionIndex(int $campaignId): Response
    {
        $this->requireLogin();

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

        return $this->renderTemplate('campaign-manager/campaigns/customers', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
        ]);
    }

    /**
     * Add customer form
     */
    public function actionAddForm(int $campaignId): Response
    {
        $this->requireLogin();

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

        return $this->renderTemplate('campaign-manager/campaigns/addCustomer', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
        ]);
    }

    /**
     * Import customers form
     */
    public function actionImportForm(int $campaignId): Response
    {
        $this->requireLogin();

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

        return $this->renderTemplate('campaign-manager/campaigns/importCustomers', [
            'campaign' => $campaign,
            'campaignId' => $campaignId,
            'site' => $site,
        ]);
    }

    /**
     * Add a customer
     */
    public function actionAdd(): ?Response
    {
        $this->requirePostRequest();

        $sms = $this->request->getParam('sms');
        $email = $this->request->getParam('email');

        // Create customer record with submitted data first (for form re-population)
        $customer = new CustomerRecord([
            'campaignId' => $this->request->getRequiredParam('campaignId'),
            'siteId' => $this->request->getRequiredParam('siteId'),
            'name' => $this->request->getParam('name'),
            'email' => $email,
            'sms' => $sms,
        ]);

        $hasErrors = false;

        // Validate name is provided
        if (empty($customer->name)) {
            $customer->addError('name', Craft::t('campaign-manager', 'Name is required.'));
            $hasErrors = true;
        }

        // Validate email format
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customer->addError('email', Craft::t('campaign-manager', 'Invalid email address.'));
            $hasErrors = true;
        }

        // Validate and sanitize phone number
        if ($sms !== null && $sms !== '') {
            $phoneValidation = PhoneHelper::validate($sms);
            if (!$phoneValidation['valid']) {
                $customer->addError('sms', $phoneValidation['error'] ?? Craft::t('campaign-manager', 'Invalid phone number.'));
                $hasErrors = true;
            } else {
                $customer->sms = $phoneValidation['e164'];
            }
        }

        // Must have at least email or SMS
        if (empty($email) && empty($sms)) {
            $customer->addError('email', Craft::t('campaign-manager', 'Email or phone number is required.'));
            $hasErrors = true;
        }

        if ($hasErrors) {
            return $this->renderAddCustomerFormWithErrors($customer, Craft::t('campaign-manager', 'Couldn\'t add customer.'));
        }

        if (!$customer->save()) {
            return $this->renderAddCustomerFormWithErrors($customer, Craft::t('campaign-manager', 'Could not save customer.'));
        }

        // Queue invitation if requested
        $sendInvitation = $this->request->getBodyParam('sendInvitation');
        if ($sendInvitation) {
            $campaign = CampaignRecord::findOneForSite($customer->campaignId, $customer->siteId);
            if ($campaign) {
                Craft::$app->getQueue()->push(new SendBatchJob([
                    'campaignId' => $customer->campaignId,
                    'siteId' => $customer->siteId,
                    'customerIds' => [$customer->id],
                    'sendSms' => !empty($customer->sms),
                    'sendEmail' => !empty($customer->email),
                ]));
            }
        }

        return $this->returnSuccessResponse($customer);
    }

    /**
     * Load customers for DataTables
     */
    public function actionLoad(): Response
    {
        $this->requireLogin();

        $length = $this->request->getRequiredParam('length');
        $offset = $this->request->getRequiredParam('start');
        $campaignId = $this->request->getRequiredParam('campaignId');
        $draw = $this->request->getRequiredParam('draw');
        $search = $this->request->getParam('search');
        $order = $this->request->getParam('order');
        $search = $search['value'] ?? null;
        $column = $order['0']['column'] ?? null;
        $dir = $order['0']['dir'] ?? null;

        /** @var ActiveQuery $query */
        $query = CustomerRecord::find()->where([
            'campaignId' => $campaignId,
            'siteId' => (int)$this->request->getRequiredParam('siteId'),
        ]);

        if (!empty($search)) {
            $query->andFilterWhere([
                'OR',
                ['like', 'name', $search],
                ['like', 'email', $search],
                ['like', 'sms', $search],
            ]);
        }

        if (!empty($column)) {
            $orderBy = [];
            switch ($column) {
                case 1:
                    $orderBy['name'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    break;
                case 2:
                    $orderBy['email'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    break;
                case 5:
                    $orderBy['smsSendDate'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    $orderBy['emailSendDate'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    break;
                case 6:
                    $orderBy['smsOpenDate'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    $orderBy['emailOpenDate'] = $dir === 'asc' ? SORT_ASC : SORT_DESC;
                    break;
            }

            $query->orderBy($orderBy);
        }

        $count = $query->count();

        $pagination = new Pagination(['totalCount' => $count]);
        $pagination->setPageSize($length);

        $customers = $query->offset($offset)
            ->limit($pagination->limit)
            ->all();

        $data = [];

        /** @var CustomerRecord $customer */
        foreach ($customers as $customer) {
            $sentDate = $customer->smsSendDate ?? $customer->emailSendDate ?? '-';

            if (is_object($sentDate)) {
                $sentDate = $sentDate->format('Y-m-d h:m:s');
            }

            $openedDate = $customer->smsOpenDate ?? $customer->emailOpenDate ?? '-';

            if (is_object($openedDate)) {
                $openedDate = $openedDate->format('Y-m-d h:m:s');
            }

            $submissionLink = $customer->submissionId
                ? Formie::$plugin->getSubmissions()->getSubmissionById($customer->submissionId)?->getCpEditUrl()
                : null;
            $submissionLink = $submissionLink
                ? '<a href="' . $submissionLink . '">' . $customer->submissionId . '</a>'
                : '-';

            $data[] = [
                $customer->getSite()->getLocale()->getLanguageID(),
                $customer->name,
                $customer->email,
                $customer->sms,
                $customer->smsInvitationCode,
                $sentDate,
                $openedDate,
                $submissionLink,
                '<a id="' . $customer->id . '" class="btn delete" href="#">Delete</a>',
            ];
        }

        $response = [
            'draw' => $draw,
            'recordsTotal' => $count,
            'recordsFiltered' => $count,
            'data' => $data,
        ];

        return $this->asJson($response);
    }

    /**
     * Download sample CSV
     */
    public function actionDownloadSample(): Response
    {
        $this->requirePostRequest();
        $templatePath = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'customers-import-template.csv';

        return Craft::$app->getResponse()->sendFile($templatePath);
    }

    /**
     * Delete a customer from the CP
     */
    public function actionDeleteFromCp(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $customerId = (int)Craft::$app->request->getRequiredBodyParam('id');

        if (!CampaignManager::$plugin->customers->deleteCustomerById($customerId)) {
            return $this->asJson(null);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Delete a customer
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $customerId = (int)Craft::$app->request->getRequiredBodyParam('id');

        if (!CampaignManager::$plugin->customers->deleteCustomerById($customerId)) {
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

        $file = UploadedFile::getInstanceByName('file');
        $campaignId = (int)$this->request->getRequiredParam('campaignId');

        if (!$file) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Please select a CSV file to upload'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        // Validate file type
        if (!$this->validateCSV($file)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Invalid file type. Please upload a CSV file.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        $queueSending = (bool)$this->request->getBodyParam('queueSending', true);

        // Save file temporarily for parsing
        $tempPath = Craft::$app->getPath()->getTempPath() . '/customer-import-' . uniqid() . '.csv';

        if (!$file->saveAs($tempPath)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Failed to save uploaded file. Please try again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        // Parse CSV
        try {
            // Auto-detect delimiter from first line
            $delimiter = $this->detectCsvDelimiter($tempPath);

            $handle = fopen($tempPath, 'r');

            if ($handle === false) {
                throw new \Exception('Could not open uploaded file for reading.');
            }

            $headers = fgetcsv($handle, 0, $delimiter);

            if (!$headers) {
                fclose($handle);
                throw new \Exception('Could not read CSV headers');
            }

            // Verify we got multiple columns (delimiter detection worked)
            if (count($headers) === 1) {
                fclose($handle);
                throw new \Exception('Could not detect CSV delimiter. The file may have only one column or use an unsupported delimiter.');
            }

            // Read ALL rows into memory
            $allRows = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $allRows[] = $row;
            }

            fclose($handle);

            // Delete temp file - we have the data in memory now
            @unlink($tempPath);

            $rowCount = count($allRows);

            // Check for reasonable size limit
            if ($rowCount > 4000) {
                throw new \Exception('CSV file is too large. Maximum 4000 rows allowed for import. Please split your file into smaller batches.');
            }

            if ($rowCount === 0) {
                throw new \Exception('CSV file is empty or contains only headers.');
            }

            // Store parsed data in session
            Craft::$app->getSession()->set('customer-import', [
                'headers' => $headers,
                'allRows' => $allRows,
                'rowCount' => $rowCount,
                'campaignId' => $campaignId,
                'queueSending' => $queueSending,
            ]);

            // Redirect to column mapping
            $siteHandle = $this->request->getParam('site', 'en');
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/map-customers?site={$siteHandle}");
        } catch (\Exception $e) {
            // Clean up temp file on error
            @unlink($tempPath);
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Failed to parse CSV: {error}', ['error' => $e->getMessage()]));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
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
        $importData = Craft::$app->getSession()->get('customer-import');

        if (!$importData || !isset($importData['allRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'No import data found. Please upload a CSV file.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        // Verify campaign ID matches
        if ($importData['campaignId'] !== $campaignId) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Campaign mismatch. Please upload the CSV file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        // Get first 5 rows for preview
        $previewRows = array_slice($importData['allRows'], 0, 5);

        return $this->renderTemplate('campaign-manager/campaigns/mapCustomers', [
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

        // Handle GET request (page refresh) - check for existing preview data
        if ($this->request->getIsGet()) {
            $previewData = Craft::$app->getSession()->get('customer-import-preview');

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
                    return $this->renderTemplate('campaign-manager/campaigns/previewCustomers', [
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
                return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
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
        $importData = Craft::$app->getSession()->get('customer-import');

        if (!$importData || !isset($importData['allRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Import session expired. Please upload the file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
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
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/map-customers?site={$siteHandle}");
        }

        // Determine default site ID from form
        $defaultSiteId = (int)$this->request->getBodyParam('defaultSiteId', 1);
        $hasLanguageMapping = in_array('language', $mappedFields);

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

            // Map CSV row to customer fields
            $customerData = [
                'name' => null,
                'email' => null,
                'sms' => null,
                'language' => '',
            ];

            foreach ($columnMap as $colIndex => $fieldName) {
                if (isset($row[$colIndex])) {
                    $value = trim($row[$colIndex]);
                    if ($value !== '') {
                        $customerData[$fieldName] = $value;
                    }
                }
            }

            // Check for missing name
            if (empty($customerData['name'])) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $customerData['name'] ?? '-',
                    'error' => Craft::t('campaign-manager', 'Missing required field: Name'),
                ];
                continue;
            }

            // Check for missing contact method
            if (empty($customerData['email']) && empty($customerData['sms'])) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $customerData['name'],
                    'error' => Craft::t('campaign-manager', 'Missing required field: Email or Phone'),
                ];
                continue;
            }

            // Determine site ID from language column or use fallback
            $language = $hasLanguageMapping ? strtolower(trim($customerData['language'] ?? '')) : '';
            if ($language === 'ar') {
                $siteId = 2;
            } elseif ($language === 'en') {
                $siteId = 1;
            } else {
                $siteId = $defaultSiteId;
            }

            // Get language code for display
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $languageCode = $site ? strtolower(substr($site->language, 0, 2)) : 'en';

            // Validate and sanitize phone number
            $sms = $customerData['sms'];
            if ($sms !== null && $sms !== '') {
                $phoneValidation = PhoneHelper::validate($sms);
                if (!$phoneValidation['valid']) {
                    $errorRows[] = [
                        'rowNumber' => $rowNumber,
                        'name' => $customerData['name'],
                        'error' => $phoneValidation['error'] ?? Craft::t('campaign-manager', 'Invalid phone number'),
                    ];
                    continue;
                }
                $sms = $phoneValidation['e164'];
            }

            // Validate email format
            $email = !empty($customerData['email']) ? strtolower(trim($customerData['email'])) : null;
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorRows[] = [
                    'rowNumber' => $rowNumber,
                    'name' => $customerData['name'],
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
                    'name' => $customerData['name'],
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
                        'name' => $customerData['name'],
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
                        'name' => $customerData['name'],
                        'identifier' => $email,
                        'reason' => Craft::t('campaign-manager', 'Same email as row {row}', ['row' => $batchEmailKeys[$emailKey]]),
                    ];
                    continue;
                }

                $batchEmailKeys[$emailKey] = $rowNumber;
            }

            // Row is valid - add to valid rows
            $validRows[] = [
                'name' => $customerData['name'],
                'email' => $email,
                'sms' => $sms,
                'siteId' => $siteId,
                'language' => $languageCode,
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
        Craft::$app->getSession()->set('customer-import-preview', [
            'validRows' => $validRows,
            'campaignId' => $campaignId,
            'siteId' => $site->id,
            'queueSending' => $queueSending,
            'summary' => $summary,
            'duplicateRows' => $duplicateRows,
            'errorRows' => $errorRows,
        ]);

        return $this->renderTemplate('campaign-manager/campaigns/previewCustomers', [
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
     * Import customers from preview (step 4 of import - actual import)
     *
     * @since 5.1.0
     */
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $campaignId = (int)$this->request->getRequiredParam('campaignId');

        // Get validated data from preview session
        $previewData = Craft::$app->getSession()->get('customer-import-preview');

        if (!$previewData || !isset($previewData['validRows'])) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Import session expired. Please upload the file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        // Verify campaign ID matches
        if ($previewData['campaignId'] !== $campaignId) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Campaign mismatch. Please upload the CSV file again.'));
            return $this->redirect("campaign-manager/campaigns/{$campaignId}/import-customers");
        }

        $queueSending = $previewData['queueSending'];
        $validRows = $previewData['validRows'];

        // Import validated rows
        $imported = 0;
        $failed = 0;
        $errorMessages = [];

        foreach ($validRows as $index => $rowData) {
            $customer = new CustomerRecord([
                'campaignId' => $campaignId,
                'siteId' => $rowData['siteId'],
                'name' => $rowData['name'],
                'email' => $rowData['email'],
                'sms' => $rowData['sms'],
            ]);

            try {
                if ($customer->save()) {
                    $imported++;
                } else {
                    $failed++;
                    $errorMessages[] = "Row " . ($index + 1) . ": " . implode(', ', $customer->getErrorSummary(true));
                }
            } catch (\Exception $e) {
                $failed++;
                $errorMessages[] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        // Clean up session data
        Craft::$app->getSession()->remove('customer-import');
        Craft::$app->getSession()->remove('customer-import-preview');

        // Build result message
        $message = Craft::t('campaign-manager', 'Successfully imported {imported} customer(s).', ['imported' => $imported]);
        if ($failed > 0) {
            $message .= ' ' . Craft::t('campaign-manager', '{failed} failed.', ['failed' => $failed]);
        }

        // Queue sending if requested and we imported customers
        if ($queueSending && $imported > 0) {
            // Get unique site IDs from imported customers
            $siteIds = CustomerRecord::find()
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
            Craft::warning('Customer import errors: ' . implode('; ', $errorMessages), 'campaign-manager');
        }

        Craft::$app->getSession()->setNotice($message);

        $siteHandle = $this->request->getParam('site', 'en');
        return $this->redirect("campaign-manager/campaigns/{$campaignId}/customers?site={$siteHandle}");
    }

    /**
     * Export customers
     */
    public function actionExportCustomers(int $campaignId): Response
    {
        $this->requireLogin();

        $request = Craft::$app->getRequest();
        $siteHandle = $request->getQueryParam('site');
        $site = $siteHandle ? Craft::$app->getSites()->getSiteByHandle($siteHandle) : Craft::$app->getSites()->getPrimarySite();
        $dateRange = $request->getQueryParam('dateRange', 'all');
        $format = $request->getQueryParam('format', 'csv');

        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $query = CustomerRecord::find()
            ->where([
                'campaignId' => $campaignId,
                'siteId' => $site->id,
            ])
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($dateRange !== 'all') {
            $query->andWhere(['>=', 'dateCreated', $startDate->format('Y-m-d 00:00:00')])
                ->andWhere(['<=', 'dateCreated', $endDate->format('Y-m-d 23:59:59')]);
        }

        /** @var CustomerRecord[] $customers */
        $customers = $query->all();

        // Build filename
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = 'customers-campaign-' . $campaignId . '-' . $dateRangeLabel . '-' . date('Y-m-d-His') . '.' . $format;

        if ($format === 'csv') {
            return $this->exportCsv($customers, $filename);
        }

        // JSON export
        $data = [];
        foreach ($customers as $customer) {
            $data[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'sms' => $customer->sms,
                'emailInvitationCode' => $customer->emailInvitationCode,
                'smsInvitationCode' => $customer->smsInvitationCode,
                'emailSendDate' => $this->formatDate($customer->emailSendDate),
                'smsSendDate' => $this->formatDate($customer->smsSendDate),
                'emailOpenDate' => $this->formatDate($customer->emailOpenDate),
                'smsOpenDate' => $this->formatDate($customer->smsOpenDate),
                'submissionId' => $customer->submissionId,
                'dateCreated' => $this->formatDate($customer->dateCreated),
            ];
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = json_encode($data, JSON_PRETTY_PRINT);

        return $response;
    }

    /**
     * Export customers as CSV
     *
     * @param CustomerRecord[] $customers
     * @param string $filename
     * @return Response
     */
    private function exportCsv(array $customers, string $filename): Response
    {
        $headers = [
            'ID',
            'Name',
            'Email',
            'SMS',
            'Email Invitation Code',
            'SMS Invitation Code',
            'Email Sent Date',
            'SMS Sent Date',
            'Email Opened Date',
            'SMS Opened Date',
            'Submission ID',
            'Date Created',
        ];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($customers as $customer) {
            fputcsv($output, [
                $customer->id,
                $customer->name,
                $customer->email,
                $customer->sms,
                $customer->emailInvitationCode,
                $customer->smsInvitationCode,
                $this->formatDate($customer->emailSendDate),
                $this->formatDate($customer->smsSendDate),
                $this->formatDate($customer->emailOpenDate),
                $this->formatDate($customer->smsOpenDate),
                $customer->submissionId,
                $this->formatDate($customer->dateCreated),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = $csv;

        return $response;
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     */
    private function getDateRangeFromParam(string $dateRange): array
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
     * Auto-detect CSV delimiter from file content
     *
     * @since 5.1.0
     */
    private function detectCsvDelimiter(string $filePath): string
    {
        $delimiters = [
            ',' => 0,
            ';' => 0,
            "\t" => 0,
            '|' => 0,
        ];

        // Read first few lines to detect delimiter
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ','; // Default to comma
        }

        $linesToCheck = 3;
        $linesChecked = 0;

        while (($line = fgets($handle)) !== false && $linesChecked < $linesToCheck) {
            foreach ($delimiters as $delimiter => $count) {
                $delimiters[$delimiter] += substr_count($line, $delimiter);
            }
            $linesChecked++;
        }

        fclose($handle);

        // Find delimiter with highest count
        $maxCount = 0;
        $detected = ',';

        foreach ($delimiters as $delimiter => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $detected = $delimiter;
            }
        }

        return $detected;
    }

    /**
     * Validate a CSV file
     */
    private function validateCSV(?UploadedFile $file): bool
    {
        if (!$file) {
            return false;
        }

        $csvMimeTypes = [
            'text/csv',
            'text/plain',
            'application/csv',
            'text/comma-separated-values',
            'application/excel',
            'application/vnd.ms-excel',
            'application/vnd.msexcel',
            'text/anytext',
            'application/octet-stream',
            'application/txt',
        ];

        $extension = strtolower($file->getExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            return false;
        }

        if (!in_array($file->type, $csvMimeTypes)) {
            return false;
        }

        return true;
    }

    /**
     * Render the add customer form with errors
     */
    protected function renderAddCustomerFormWithErrors(CustomerRecord $customer, ?string $errorMessage = null): Response
    {
        if ($errorMessage) {
            Craft::$app->getSession()->setError($errorMessage);
        }

        $siteId = $customer->siteId;
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $campaign = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($customer->campaignId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $this->renderTemplate('campaign-manager/campaigns/addCustomer', [
            'campaign' => $campaign,
            'campaignId' => $customer->campaignId,
            'site' => $site,
            'customer' => $customer,
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

    /**
     * Format a date value for export (handles both DateTime objects and strings)
     */
    private function formatDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d H:i:s');
        }

        // Already a string
        return (string) $date;
    }
}
