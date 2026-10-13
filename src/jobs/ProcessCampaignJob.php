<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\jobs;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use Exception;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\exceptions\InvalidInvitationDelayException;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\helpers\InvitationScheduleHelper;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
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
 * @since     5.0.0
 */
class ProcessCampaignJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
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

        // Get pending recipient IDs (not full records to save memory), each
        // with how long it still has to wait for the campaign's invitation delay
        $waitByRecipientId = $this->getPendingRecipientWaits($campaign);
        $totalRecipients = count($waitByRecipientId);

        $this->logInfo('Processing campaign', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'pendingRecipients' => $totalRecipients,
        ]);

        if ($totalRecipients === 0) {
            $this->logInfo('No pending recipients to process');
            return;
        }

        // Split into batches and queue each batch. Recipients eligible now
        // are batched first and sent now. The rest are in the order they
        // become eligible, so each of their batches waits as long as its
        // last recipient and no recipient is sent to before its time.
        $ready = array_keys(array_filter($waitByRecipientId, static fn(int $wait): bool => $wait === 0));
        $waiting = array_keys(array_filter($waitByRecipientId, static fn(int $wait): bool => $wait > 0));
        $batches = array_merge(array_chunk($ready, self::BATCH_SIZE), array_chunk($waiting, self::BATCH_SIZE));
        $totalBatches = count($batches);

        $this->logInfo('Creating batch jobs', [
            'totalBatches' => $totalBatches,
            'batchSize' => self::BATCH_SIZE,
        ]);

        foreach ($batches as $index => $batchIds) {
            $wait = $waitByRecipientId[end($batchIds)];
            Queue::push(new SendBatchJob([
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
                'recipientIds' => $batchIds,
                'sendSms' => $this->sendSms,
                'sendEmail' => $this->sendEmail,
                'triggeredByUserId' => $this->triggeredByUserId,
                // Nothing is sent if the campaign is disabled for the site by
                // the time the batch runs, however long it waited
                'onlyWhileCampaignEnabled' => true,
            ]), null, $wait);

            if ($wait > 0) {
                $this->logInfo('Batch waits for the invitation delay', [
                    'campaignId' => $this->campaignId,
                    'siteId' => $this->siteId,
                    'recipientCount' => count($batchIds),
                    'waitSeconds' => $wait,
                ]);
            }

            $this->setProgress($queue, ($index + 1) / $totalBatches);
        }

        $this->logInfo('Batch jobs queued', [
            'campaignId' => $this->campaignId,
            'totalBatches' => $totalBatches,
            'totalRecipients' => $totalRecipients,
        ]);

        CampaignManager::$plugin->activityLogs->log('campaign_batches_queued', [
            'campaignId' => $this->campaignId,
            'source' => 'queue',
            'summary' => Craft::t('campaign-manager', 'Campaign batches queued'),
            'userId' => $this->triggeredByUserId,
            'details' => [
                'siteId' => $this->siteId,
                'siteName' => Craft::$app->getSites()->getSiteById($this->siteId)?->name,
                'totalBatches' => $totalBatches,
                'totalRecipients' => $totalRecipients,
                'sendSms' => $this->sendSms,
                'sendEmail' => $this->sendEmail,
                'triggeredByUserId' => $this->triggeredByUserId,
            ],
            'siteScope' => ActivityLogRecord::SCOPE_SITES,
            'siteIds' => [(int)$this->siteId],
        ]);
    }

    /**
     * Get pending recipient IDs (recipients that need SMS or email), in the
     * order they become eligible for the campaign's invitation delay, each
     * with the seconds it still has to wait.
     *
     * @return array<int, int> Seconds to wait by recipient ID; 0 when eligible now
     */
    private function getPendingRecipientWaits(CampaignRecord $campaign): array
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
            ->select(['id', 'dateCreated'])
            ->where(['campaignId' => $campaign->id, 'siteId' => $this->siteId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

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

        $rows = $query->asArray()->all();
        if ($rows === []) {
            return [];
        }

        // A delay that cannot be read must not be mistaken for no delay:
        // no batch is queued until an administrator corrects the campaign
        try {
            InvitationScheduleHelper::delayInterval($campaign->invitationDelayPeriod);
        } catch (InvalidInvitationDelayException $e) {
            $this->logError('Invalid invitation delay period; the campaign is not run until it is corrected', [
                'campaignId' => $campaign->id,
                'siteId' => $this->siteId,
                'invitationDelayPeriod' => $campaign->invitationDelayPeriod,
                'pendingRecipients' => count($rows),
                'error' => $e->getMessage(),
            ]);

            // No batch exists yet, so the queue is told what actually
            // happened: the run itself was refused
            throw new SendBatchFailedException(Craft::t(
                'campaign-manager',
                'Campaign {campaignId} could not be run: its invitation delay is invalid and no invitations were queued. Correct the delay on the campaign, then run it again.',
                ['campaignId' => $this->campaignId]
            ));
        }

        $waits = [];
        $now = DateTimeHelper::now();
        foreach ($rows as $row) {
            $waits[(int)$row['id']] = InvitationScheduleHelper::secondsUntilEligible((string)$row['dateCreated'], $campaign->invitationDelayPeriod, $now);
        }

        return $waits;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // Retrying cannot read a delay the campaign still carries
        if ($error instanceof SendBatchFailedException) {
            return false;
        }

        return ($attempt < 3) && ($error instanceof Exception);
    }
}
