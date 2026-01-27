<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use Exception;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\PhoneHelper;
use lindemannrock\campaignmanager\records\CustomerRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Import Customers Job
 *
 * Processes a CSV file and imports customers in batches.
 * After import completes, queues ProcessCampaignJob for each site.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class ImportCustomersJob extends BaseJob implements RetryableJobInterface
{
    use LoggingTrait;

    public const BATCH_SIZE = 100;

    /**
     * @var int Campaign ID
     */
    public int $campaignId;

    /**
     * @var string Path to the CSV file
     */
    public string $csvPath;

    /**
     * @var bool Whether to queue sending jobs after import
     */
    public bool $queueSending = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('campaign-manager');
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = CampaignManager::$plugin->getSettings();
        return Craft::t('campaign-manager', '{pluginName}: Importing customers for campaign #{id}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->campaignId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (!file_exists($this->csvPath)) {
            $this->logError('CSV file not found', ['path' => $this->csvPath]);
            return;
        }

        $handle = fopen($this->csvPath, 'r');
        if ($handle === false) {
            $this->logError('Could not open CSV file', ['path' => $this->csvPath]);
            return;
        }

        // Skip header row
        fgetcsv($handle);

        $totalRows = 0;
        $totalSaved = 0;
        $errors = 0;
        $skipped = 0;
        $siteIdsWithCustomers = [];
        $batch = [];

        $this->logInfo('Starting CSV import', [
            'campaignId' => $this->campaignId,
            'csvPath' => $this->csvPath,
        ]);

        // Count total rows for progress (stream through once)
        $countHandle = fopen($this->csvPath, 'r');
        fgetcsv($countHandle); // Skip header
        $rowCount = 0;
        while (fgetcsv($countHandle) !== false) {
            $rowCount++;
        }
        fclose($countHandle);

        $processedRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $totalRows++;
            $processedRows++;

            // Skip empty rows
            if (empty($row[0])) {
                continue;
            }

            try {
                // Determine site ID from language column
                $language = $row[3] ?? 'en';
                $siteId = $language === 'ar' ? 2 : 1;

                $name = $row[0];
                $email = !empty($row[1]) ? trim($row[1]) : null;
                $sms = !empty($row[2]) ? trim($row[2]) : null;

                // Pre-validate phone number before creating record
                if ($sms !== null) {
                    $phoneValidation = PhoneHelper::validate($sms);
                    if (!$phoneValidation['valid']) {
                        $skipped++;
                        $this->logWarning('Skipping row with invalid phone', [
                            'row' => $totalRows,
                            'name' => $name,
                            'sms' => $sms,
                            'error' => $phoneValidation['error'],
                        ]);
                        continue;
                    }
                    // Use E.164 formatted version
                    $sms = $phoneValidation['e164'];
                }

                $customer = new CustomerRecord([
                    'campaignId' => $this->campaignId,
                    'siteId' => $siteId,
                    'name' => $name,
                    'email' => $email,
                    'sms' => $sms,
                ]);

                $batch[] = $customer;

                // Track which sites have customers
                if (!in_array($siteId, $siteIdsWithCustomers)) {
                    $siteIdsWithCustomers[] = $siteId;
                }

                // Process batch when it reaches the size limit
                if (count($batch) >= self::BATCH_SIZE) {
                    $result = $this->saveBatch($batch);
                    $totalSaved += $result['saved'];
                    $errors += $result['errors'];
                    $batch = [];

                    $this->setProgress($queue, $processedRows / $rowCount);
                }
            } catch (Exception $e) {
                $this->logError('Error parsing CSV row', [
                    'row' => $totalRows,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        fclose($handle);

        // Save remaining batch
        if (count($batch) > 0) {
            $result = $this->saveBatch($batch);
            $totalSaved += $result['saved'];
            $errors += $result['errors'];
        }

        // Clean up temp file
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }

        $this->logInfo('CSV import complete', [
            'campaignId' => $this->campaignId,
            'totalRows' => $totalRows,
            'totalSaved' => $totalSaved,
            'skipped' => $skipped,
            'errors' => $errors,
            'sitesWithCustomers' => $siteIdsWithCustomers,
        ]);

        // Queue ProcessCampaignJob for each site that received customers
        if ($this->queueSending && !empty($siteIdsWithCustomers)) {
            foreach ($siteIdsWithCustomers as $siteId) {
                Craft::$app->getQueue()->push(new ProcessCampaignJob([
                    'campaignId' => $this->campaignId,
                    'siteId' => $siteId,
                    'sendSms' => true,
                    'sendEmail' => true,
                ]));

                $this->logInfo('Queued ProcessCampaignJob after import', [
                    'campaignId' => $this->campaignId,
                    'siteId' => $siteId,
                ]);
            }
        }
    }

    /**
     * Save a batch of customer records
     *
     * @param CustomerRecord[] $batch
     * @return array{saved: int, errors: int}
     */
    private function saveBatch(array $batch): array
    {
        $saved = 0;
        $errors = 0;

        foreach ($batch as $customer) {
            try {
                if ($customer->save()) {
                    $saved++;
                } else {
                    $errors++;
                    $this->logWarning('Failed to save customer', [
                        'name' => $customer->name,
                        'errors' => $customer->getErrorSummary(true),
                    ]);
                }
            } catch (Exception $e) {
                $errors++;
                $this->logError('Exception saving customer', [
                    'name' => $customer->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // 30 minutes for large imports
        return 1800;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return ($attempt < 3) && ($error instanceof Exception);
    }
}
