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
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Process Campaign Job
 *
 * Finds pending recipients and spawns batch jobs for sending invitations.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class ProcessCampaignJob extends BaseJob implements RetryableJobInterface
{
    use LoggingTrait;

    public const BATCH_SIZE = 50;

    /**
     * @var int Campaign ID
     */
    public int $campaignId;

    /**
     * @var int Site ID
     */
    public int $siteId;

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
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = CampaignManager::$plugin->getSettings();
        return Craft::t('campaign-manager', '{pluginName}: Processing campaign #{id}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->campaignId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Check if campaign element is enabled
        $campaignElement = \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($this->campaignId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        if (!$campaignElement || !$campaignElement->enabled) {
            $this->logInfo('Skipping disabled campaign', [
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
            ]);
            return;
        }

        $campaign = CampaignRecord::findOneForSite($this->campaignId, $this->siteId);

        if (!$campaign) {
            $this->logError('Campaign not found', [
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
            ]);
            return;
        }

        // Get pending recipient IDs (not full records to save memory)
        $recipientIds = $this->getPendingRecipientIds($campaign);
        $totalRecipients = count($recipientIds);

        $this->logInfo('Processing campaign', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'pendingRecipients' => $totalRecipients,
        ]);

        if ($totalRecipients === 0) {
            $this->logInfo('No pending recipients to process');
            return;
        }

        // Split into batches and queue each batch
        $batches = array_chunk($recipientIds, self::BATCH_SIZE);
        $totalBatches = count($batches);

        $this->logInfo('Creating batch jobs', [
            'totalBatches' => $totalBatches,
            'batchSize' => self::BATCH_SIZE,
        ]);

        foreach ($batches as $index => $batchIds) {
            Craft::$app->getQueue()->push(new SendBatchJob([
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
                'recipientIds' => $batchIds,
                'sendSms' => $this->sendSms,
                'sendEmail' => $this->sendEmail,
            ]));

            $this->setProgress($queue, ($index + 1) / $totalBatches);
        }

        $this->logInfo('Batch jobs queued', [
            'campaignId' => $this->campaignId,
            'totalBatches' => $totalBatches,
            'totalRecipients' => $totalRecipients,
        ]);
    }

    /**
     * Get pending recipient IDs (recipients that need SMS or email)
     *
     * @return int[]
     */
    private function getPendingRecipientIds(CampaignRecord $campaign): array
    {
        // Load translatable content for this site
        $content = $campaign->getContentForSite($this->siteId);

        if (!$content) {
            $this->logWarning('No campaign content for site', [
                'campaignId' => $campaign->id,
                'siteId' => $this->siteId,
            ]);
            return [];
        }

        $query = RecipientRecord::find()
            ->select(['id'])
            ->where(['campaignId' => $campaign->id, 'siteId' => $this->siteId]);

        // Build conditions based on what we're sending
        $conditions = ['or'];

        if ($this->sendSms && !empty($content->smsInvitationMessage)) {
            // Has phone, no SMS sent yet
            $conditions[] = [
                'and',
                ['not', ['sms' => null]],
                ['not', ['sms' => '']],
                ['smsSendDate' => null],
            ];
        }

        if ($this->sendEmail && !empty($content->emailInvitationMessage)) {
            // Has email, no email sent yet
            $conditions[] = [
                'and',
                ['not', ['email' => null]],
                ['not', ['email' => '']],
                ['emailSendDate' => null],
            ];
        }

        // Only add conditions if we have something to send
        if (count($conditions) > 1) {
            $query->andWhere($conditions);
        } else {
            // Nothing to send - log why
            $this->logWarning('No invitation messages configured for sending', [
                'campaignId' => $campaign->id,
                'siteId' => $this->siteId,
                'sendSms' => $this->sendSms,
                'sendEmail' => $this->sendEmail,
                'hasSmsMessage' => !empty($content->smsInvitationMessage),
                'hasEmailMessage' => !empty($content->emailInvitationMessage),
            ]);
            return [];
        }

        $results = $query->column();

        return array_map('intval', $results);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // 5 minutes to queue all batches
        return 300;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return ($attempt < 3) && ($error instanceof Exception);
    }
}
