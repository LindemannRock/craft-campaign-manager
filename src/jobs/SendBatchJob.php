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
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Send Batch Job
 *
 * Sends SMS and/or email invitations to a batch of recipients.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class SendBatchJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
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
     * @var int[] Recipient IDs to process
     */
    public array $recipientIds = [];

    /**
     * @var bool Whether to send SMS
     */
    public bool $sendSms = true;

    /**
     * @var bool Whether to send email
     */
    public bool $sendEmail = true;

    /**
     * @var int|null User ID who triggered this queue run
     *
     * @since 5.4.0
     */
    public ?int $triggeredByUserId = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = CampaignManager::$plugin->getSettings();
        $count = count($this->recipientIds);
        return Craft::t('campaign-manager', '{pluginName}: Sending invitations to {count} recipients', [
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

        $totalRecipients = count($this->recipientIds);
        $processed = 0;
        $smsSent = 0;
        $emailSent = 0;
        $errors = 0;

        $this->logInfo('Processing batch', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'recipientCount' => $totalRecipients,
        ]);

        // Batch-fetch all recipients in one query (was findOne() per iteration)
        /** @var RecipientRecord[] $recipientRecords */
        $recipientRecords = RecipientRecord::find()
            ->where(['id' => $this->recipientIds])
            ->all();
        $recipientsById = [];
        foreach ($recipientRecords as $record) {
            $recipientsById[$record->id] = $record;
        }

        foreach ($this->recipientIds as $recipientId) {
            $recipient = $recipientsById[$recipientId] ?? null;

            if (!$recipient) {
                $this->logWarning('Recipient not found', ['recipientId' => $recipientId]);
                $errors++;
                $processed++;
                $this->setProgress($queue, $processed / $totalRecipients);
                continue;
            }

            // Send SMS if enabled and recipient has phone and hasn't received SMS yet
            if ($this->sendSms && !empty($recipient->sms) && empty($recipient->smsSendDate)) {
                if (!empty($content->smsInvitationMessage)) {
                    $result = CampaignManager::$plugin->recipients->processSmsInvitation(
                        $campaign,
                        $recipient,
                        $campaign->providerHandle,
                        $campaign->senderId,
                    );

                    if ($result) {
                        $smsSent++;
                        $this->logInfo('SMS sent', ['recipientId' => $recipientId]);
                    } else {
                        $errors++;
                        $this->logError('SMS failed', ['recipientId' => $recipientId]);
                    }
                } else {
                    $this->logWarning('No SMS invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            // Send email if enabled and recipient has email and hasn't received email yet
            if ($this->sendEmail && !empty($recipient->email) && empty($recipient->emailSendDate)) {
                if (!empty($content->emailInvitationMessage)) {
                    $result = CampaignManager::$plugin->emails->sendNotificationEmail(
                        $recipient,
                        $campaign
                    );

                    if ($result) {
                        $emailSent++;
                        $this->logInfo('Email sent', ['recipientId' => $recipientId]);
                    } else {
                        $errors++;
                        $this->logError('Email failed', ['recipientId' => $recipientId]);
                    }
                } else {
                    $this->logWarning('No email invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            $processed++;
            $this->setProgress($queue, $processed / $totalRecipients);
        }

        $logContext = [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'smsSent' => $smsSent,
            'emailSent' => $emailSent,
            'errors' => $errors,
        ];

        if ($smsSent === 0 && $emailSent === 0 && $errors > 0) {
            $this->logError('Batch failed', $logContext);
        } elseif ($errors > 0) {
            $this->logWarning('Batch complete with errors', $logContext);
        } else {
            $this->logInfo('Batch complete', $logContext);
        }

        CampaignManager::$plugin->activityLogs->log('invitations_sent_batch', [
            'campaignId' => $this->campaignId,
            'source' => 'queue',
            'summary' => Craft::t('campaign-manager', 'Invitation batch sent'),
            'userId' => $this->triggeredByUserId,
            'details' => [
                'siteId' => $this->siteId,
                'siteName' => Craft::$app->getSites()->getSiteById($this->siteId)?->name,
                'recipientCount' => $totalRecipients,
                'smsSent' => $smsSent,
                'emailSent' => $emailSent,
                'errors' => $errors,
                'triggeredByUserId' => $this->triggeredByUserId,
            ],
        ]);

        // Surface total send failures to the queue UI. Partial successes (some sent,
        // some errored) complete normally — re-running would re-send to recipients
        // who already received their invite. Zero-success batches are almost always
        // a config/template/provider issue that retrying won't fix.
        if ($smsSent === 0 && $emailSent === 0 && $errors > 0) {
            throw new SendBatchFailedException(Craft::t(
                'campaign-manager',
                'Campaign {campaignId} batch failed: {errors} of {total} recipients could not be sent. Check the plugin log for details.',
                [
                    'campaignId' => $this->campaignId,
                    'errors' => $errors,
                    'total' => $totalRecipients,
                ]
            ));
        }
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // Config/template/provider failures won't be fixed by retrying — let the
        // operator see the failure and address the root cause instead.
        if ($error instanceof SendBatchFailedException) {
            return false;
        }

        return ($attempt < 3) && ($error instanceof Exception);
    }
}
