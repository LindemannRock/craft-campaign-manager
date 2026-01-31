<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\jobs;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use Exception;
use lindemannrock\campaignmanager\behaviors\CampaignBehavior;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Trigger Campaign Job
 *
 * Triggers pending recipients in a campaign to receive SMS and email invitations.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class TriggerCampaignJob extends BaseJob implements RetryableJobInterface
{
    use LoggingTrait;

    /**
     * @var int|null Campaign ID to process (null = all campaigns)
     */
    public ?int $campaignId = null;

    /**
     * @var string|null Provider handle to use for SMS (uses campaign's providerHandle if null)
     */
    public ?string $providerHandle = null;

    /**
     * @var string|null Sender ID handle to use for SMS (uses campaign's senderId if null)
     */
    public ?string $senderIdHandle = null;

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
        return Craft::t('campaign-manager', '{pluginName}: Triggering campaign invitation(s)', [
            'pluginName' => $settings->getDisplayName(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $campaigns = [];
        if (!empty($this->campaignId)) {
            $this->logInfo('Processing campaign', ['campaignId' => $this->campaignId]);
            $campaigns = CampaignManager::$plugin->campaigns->find()->id($this->campaignId)->siteId('*')->all();
        } else {
            $this->logInfo('Processing all campaigns');
            $campaigns = CampaignManager::$plugin->campaigns->find()->siteId('*')->all();
        }

        $this->sendSms($campaigns);
        $this->sendEmails($campaigns);

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return ($attempt < 5) && ($error instanceof Exception);
    }

    /**
     * Send email invitations
     *
     * @param array<int, Element|ElementInterface> $campaigns
     */
    private function sendEmails(array $campaigns): void
    {
        $step = 0;
        $failed = 0;
        $success = 0;
        $totalRecipients = 0;

        foreach ($campaigns as $campaign) {
            $this->logInfo('Processing Campaign Site for emails', ['siteId' => $campaign->siteId]);

            /** @var CampaignBehavior|null $behavior */
            $behavior = $campaign->getBehavior('campaignManager');
            $record = $behavior?->getCampaignManagerRecord();

            if (!$campaign->enabled) {
                $this->logWarning('Processing invitation requires an enabled Campaign', ['id' => $campaign->id]);
                $step++;
                continue;
            }

            if (!$record instanceof CampaignRecord) {
                $this->logWarning('Campaign has no campaign record', ['id' => $campaign->id]);
                $step++;
                continue;
            }

            // Get content record for translatable fields
            $contentRecord = $record->getContentForSite($campaign->siteId);

            if (!$contentRecord || !$contentRecord->emailInvitationMessage) {
                $this->logWarning('Processing invitation requires a non-empty Email Invitation Message');
                $step++;
                continue;
            }

            if (!$contentRecord->emailInvitationSubject) {
                $this->logWarning('Processing invitation requires a non-empty Email Invitation Subject');
                $step++;
                continue;
            }

            $recipients = $record->getPendingEmailRecipients($campaign->siteId);
            $totalRecipients += count($recipients);
            $step++;

            $this->logInfo('Processing email recipients', [
                'count' => count($recipients),
                'campaignId' => $campaign->id,
            ]);

            /** @var RecipientRecord $recipient */
            foreach ($recipients as $recipient) {
                $result = false;

                if (empty($recipient->email)) {
                    $success++;
                    $this->logWarning('Skipping email notification as email is not valid');
                    continue;
                }

                try {
                    $result = CampaignManager::$plugin->emails->sendNotificationEmail(
                        $recipient,
                        $record
                    );
                } catch (Exception $e) {
                    $this->logError('Email send failed', ['error' => $e->getMessage()]);
                }

                if ($result) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        }

        $this->logInfo('Campaign Email trigger finished', [
            'total' => $totalRecipients,
            'success' => $success,
            'failed' => $failed,
        ]);
    }

    /**
     * Send SMS invitations
     *
     * @param array<int, Element|ElementInterface> $campaigns
     */
    private function sendSms(array $campaigns): void
    {
        $step = 0;
        $failed = 0;
        $success = 0;
        $totalRecipients = 0;

        foreach ($campaigns as $campaign) {
            /** @var CampaignBehavior|null $behavior */
            $behavior = $campaign->getBehavior('campaignManager');
            $record = $behavior?->getCampaignManagerRecord();

            if (!$campaign->enabled) {
                $this->logWarning('Processing invitation requires an enabled Campaign', ['id' => $campaign->id]);
                $step++;
                continue;
            }

            if (!$record instanceof CampaignRecord) {
                $this->logWarning('Campaign has no campaign record', ['id' => $campaign->id]);
                $step++;
                continue;
            }

            // Check for provider/sender ID - either from campaign record or job parameter
            $providerHandle = $this->providerHandle ?? $record->providerHandle;
            $senderIdHandle = $this->senderIdHandle ?? $record->senderId;

            // If no provider or sender ID is configured, skip
            if (empty($providerHandle) && empty($senderIdHandle)) {
                $this->logWarning('Invalid Campaign, requires a Provider and Sender ID', ['id' => $campaign->id]);
                $step++;
                continue;
            }

            // Get content record for translatable fields
            $contentRecord = $record->getContentForSite($campaign->siteId);

            if (!$contentRecord || !$contentRecord->smsInvitationMessage) {
                $this->logWarning('Processing invitation requires a non-empty SMS Invitation Message');
                $step++;
                continue;
            }

            $recipients = $record->getPendingSmsRecipients($campaign->siteId);
            $totalRecipients += count($recipients);
            $step++;

            $this->logInfo('Processing SMS recipients', [
                'count' => count($recipients),
                'campaignId' => $campaign->id,
            ]);

            /** @var RecipientRecord $recipient */
            foreach ($recipients as $recipient) {
                if (empty($recipient->sms)) {
                    $success++;
                    $this->logWarning('Skipping SMS notification as phone number is not valid');
                    continue;
                }

                // Use SMS Manager to send SMS
                $result = CampaignManager::$plugin->recipients->processSmsInvitation(
                    $record,
                    $recipient,
                    $providerHandle,
                    $senderIdHandle,
                );

                if ($result) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        }

        $this->logInfo('Campaign SMS trigger finished', [
            'total' => $totalRecipients,
            'success' => $success,
            'failed' => $failed,
        ]);
    }
}
