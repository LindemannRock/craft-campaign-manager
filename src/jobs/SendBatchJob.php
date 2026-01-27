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
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\CustomerRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Send Batch Job
 *
 * Sends SMS and/or email invitations to a batch of customers.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class SendBatchJob extends BaseJob implements RetryableJobInterface
{
    use LoggingTrait;

    /**
     * @var int Campaign ID
     */
    public int $campaignId;

    /**
     * @var int Site ID
     */
    public int $siteId;

    /**
     * @var int[] Customer IDs to process
     */
    public array $customerIds = [];

    /**
     * @var bool Whether to send SMS
     */
    public bool $sendSms = true;

    /**
     * @var bool Whether to send email
     */
    public bool $sendEmail = true;

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
        $count = count($this->customerIds);
        return Craft::t('campaign-manager', '{pluginName}: Sending invitations to {count} customers', [
            'pluginName' => $settings->getDisplayName(),
            'count' => $count,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $campaign = CampaignRecord::findOneForSite($this->campaignId, $this->siteId);

        if (!$campaign) {
            $this->logError('Campaign not found', [
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
            ]);
            return;
        }

        // Load translatable content for this site
        $content = $campaign->getContentForSite($this->siteId);

        if (!$content) {
            $this->logError('Campaign content not found for site', [
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
            ]);
            return;
        }

        $totalCustomers = count($this->customerIds);
        $processed = 0;
        $smsSent = 0;
        $emailSent = 0;
        $errors = 0;

        $this->logInfo('Processing batch', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'customerCount' => $totalCustomers,
        ]);

        foreach ($this->customerIds as $customerId) {
            $customer = CustomerRecord::findOne($customerId);

            if (!$customer) {
                $this->logWarning('Customer not found', ['customerId' => $customerId]);
                $errors++;
                $processed++;
                $this->setProgress($queue, $processed / $totalCustomers);
                continue;
            }

            // Send SMS if enabled and customer has phone and hasn't received SMS yet
            if ($this->sendSms && !empty($customer->sms) && empty($customer->smsSendDate)) {
                if (!empty($content->smsInvitationMessage)) {
                    $senderIdId = $campaign->senderId ? (int) $campaign->senderId : null;
                    $result = CampaignManager::$plugin->customers->processSmsInvitation(
                        $campaign,
                        $customer,
                        $senderIdId
                    );

                    if ($result) {
                        $smsSent++;
                        $this->logInfo('SMS sent', ['customerId' => $customerId]);
                    } else {
                        $errors++;
                        $this->logError('SMS failed', ['customerId' => $customerId]);
                    }
                } else {
                    $this->logWarning('No SMS invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            // Send email if enabled and customer has email and hasn't received email yet
            if ($this->sendEmail && !empty($customer->email) && empty($customer->emailSendDate)) {
                if (!empty($content->emailInvitationMessage)) {
                    $result = CampaignManager::$plugin->emails->sendNotificationEmail(
                        $customer,
                        $campaign
                    );

                    if ($result) {
                        $emailSent++;
                        $this->logInfo('Email sent', ['customerId' => $customerId]);
                    } else {
                        $errors++;
                        $this->logError('Email failed', ['customerId' => $customerId]);
                    }
                } else {
                    $this->logWarning('No email invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            $processed++;
            $this->setProgress($queue, $processed / $totalCustomers);
        }

        $this->logInfo('Batch complete', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'smsSent' => $smsSent,
            'emailSent' => $emailSent,
            'errors' => $errors,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // 10 minutes per batch
        return 600;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return ($attempt < 3) && ($error instanceof Exception);
    }
}
