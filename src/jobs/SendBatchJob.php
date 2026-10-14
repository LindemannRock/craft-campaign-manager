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
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\exceptions\InvalidInvitationDelayException;
use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\helpers\InvitationScheduleHelper;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\queue\RetryableJobInterface;

/**
 * Send Batch Job
 *
 * Sends SMS and/or email invitations to a batch of recipients. A recipient
 * whose invitation delay has not passed when the batch runs is not sent to;
 * such recipients are queued again to run once the last of them is eligible.
 * A campaign whose stored delay cannot be read fails the batch before any
 * invitation is attempted.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class SendBatchJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    private const OUTCOME_SENT = 'sent';
    private const OUTCOME_FAILED = 'failed';
    private const OUTCOME_SKIPPED = 'skipped';
    private const OUTCOME_RECIPIENT_DELETED = 'recipientDeleted';

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
     * @var bool Whether to send only while the campaign is still enabled for
     * the site when the batch runs. Off by default so batches queued without
     * it, including ones stored before it existed, behave as before.
     *
     * @since 5.16.0
     */
    public bool $onlyWhileCampaignEnabled = false;

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
        if ($this->onlyWhileCampaignEnabled && !$this->campaignIsEnabledForSite()) {
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

        // A delay that cannot be read must not be mistaken for no delay:
        // nothing is attempted until an administrator corrects the campaign
        try {
            InvitationScheduleHelper::delayInterval($campaign->invitationDelayPeriod);
        } catch (InvalidInvitationDelayException $e) {
            $this->logError('Invalid invitation delay period; nothing is sent until the campaign is corrected', [
                'campaignId' => $this->campaignId,
                'siteId' => $this->siteId,
                'invitationDelayPeriod' => $campaign->invitationDelayPeriod,
                'recipientCount' => $totalRecipients,
                'error' => $e->getMessage(),
            ]);

            throw $this->batchFailed($totalRecipients, $totalRecipients);
        }

        $processed = 0;
        $smsSent = 0;
        $emailSent = 0;
        $errors = 0;
        // Recipients whose invitation delay has not passed yet, with the
        // seconds each still has to wait; they are queued again below
        /** @var array<int, int> $waiting */
        $waiting = [];
        $now = DateTimeHelper::now();

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

            $recipientDeleted = false;
            $smsPending = $this->sendSms && !empty($recipient->sms) && empty($recipient->smsSendDate);
            $emailPending = $this->sendEmail && !empty($recipient->email) && empty($recipient->emailSendDate);

            // The campaign's invitation delay counts from when the recipient
            // was added and is read now, so a batch that runs early, or a
            // delay lengthened since it was queued, never sends early
            if ($smsPending || $emailPending) {
                $wait = InvitationScheduleHelper::secondsUntilEligible($recipient->dateCreated, $campaign->invitationDelayPeriod, $now);
                if ($wait > 0) {
                    $waiting[(int)$recipientId] = $wait;
                    $processed++;
                    $this->setProgress($queue, $processed / $totalRecipients);
                    continue;
                }
            }

            // Send SMS if enabled and recipient has phone and hasn't received SMS yet
            if ($smsPending) {
                if (!empty($content->smsInvitationMessage)) {
                    $outcome = $this->sendOncePerChannel(
                        (int)$recipientId,
                        'sms',
                        static fn(RecipientRecord $pending): bool => CampaignManager::$plugin->recipients->processSmsInvitation(
                            $campaign,
                            $pending,
                            $campaign->providerHandle,
                            $campaign->senderHandle,
                        ),
                    );

                    if ($outcome === self::OUTCOME_SENT) {
                        $smsSent++;
                        $this->logInfo('SMS sent', ['recipientId' => $recipientId]);
                    } elseif ($outcome === self::OUTCOME_FAILED) {
                        $errors++;
                        $this->logError('SMS failed', ['recipientId' => $recipientId]);
                    } elseif ($outcome === self::OUTCOME_RECIPIENT_DELETED) {
                        $recipientDeleted = true;
                    }
                } else {
                    $this->logWarning('No SMS invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            // Send email if enabled and recipient has email and hasn't received email yet
            if (!$recipientDeleted && $emailPending) {
                if (!empty($content->emailInvitationMessage)) {
                    $outcome = $this->sendOncePerChannel(
                        (int)$recipientId,
                        'email',
                        static fn(RecipientRecord $pending): bool => CampaignManager::$plugin->emails->sendNotificationEmail(
                            $pending,
                            $campaign
                        ),
                    );

                    if ($outcome === self::OUTCOME_SENT) {
                        $emailSent++;
                        $this->logInfo('Email sent', ['recipientId' => $recipientId]);
                    } elseif ($outcome === self::OUTCOME_FAILED) {
                        $errors++;
                        $this->logError('Email failed', ['recipientId' => $recipientId]);
                    } elseif ($outcome === self::OUTCOME_RECIPIENT_DELETED) {
                        $recipientDeleted = true;
                    }
                } else {
                    $this->logWarning('No email invitation message configured', [
                        'campaignId' => $this->campaignId,
                        'siteId' => $this->siteId,
                    ]);
                }
            }

            if ($recipientDeleted) {
                $this->logWarning('Recipient not found', ['recipientId' => $recipientId]);
                $errors++;
            }

            $processed++;
            $this->setProgress($queue, $processed / $totalRecipients);
        }

        if ($waiting !== []) {
            $this->requeueWaiting($waiting);
        }

        $logContext = [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'smsSent' => $smsSent,
            'emailSent' => $emailSent,
            'errors' => $errors,
            'waiting' => count($waiting),
        ];

        if (count($waiting) === $totalRecipients) {
            // Nothing was attempted; the batch continues later
            $this->logInfo('Batch waits for the invitation delay', $logContext);
            return;
        }

        if ($smsSent === 0 && $emailSent === 0 && $errors > 0) {
            $this->logError('Batch failed', $logContext);
        } elseif ($errors > 0) {
            $this->logWarning('Batch complete with errors', $logContext);
        } else {
            $this->logInfo('Batch complete', $logContext);
        }

        $details = [
            'siteId' => $this->siteId,
            'siteName' => Craft::$app->getSites()->getSiteById($this->siteId)?->name,
            'recipientCount' => $totalRecipients,
            'smsSent' => $smsSent,
            'emailSent' => $emailSent,
            'errors' => $errors,
        ];
        if ($waiting !== []) {
            // Recipients held back by the invitation delay and queued again
            $details['waiting'] = count($waiting);
        }
        $details['triggeredByUserId'] = $this->triggeredByUserId;

        CampaignManager::$plugin->activityLogs->log('invitations_sent_batch', [
            'campaignId' => $this->campaignId,
            'source' => 'queue',
            'summary' => Craft::t('campaign-manager', 'Invitation batch sent'),
            'userId' => $this->triggeredByUserId,
            'details' => $details,
            'siteScope' => ActivityLogRecord::SCOPE_SITES,
            'siteIds' => [(int)$this->siteId],
        ]);

        // Surface total send failures to the queue UI. Partial successes (some sent,
        // some errored) complete normally — re-running would re-send to recipients
        // who already received their invite. Zero-success batches are almost always
        // a config/template/provider issue that retrying won't fix.
        if ($smsSent === 0 && $emailSent === 0 && $errors > 0) {
            throw $this->batchFailed($errors, $totalRecipients);
        }
    }

    /**
     * The failure the queue shows for a batch that could send nothing.
     */
    private function batchFailed(int $errors, int $totalRecipients): SendBatchFailedException
    {
        return new SendBatchFailedException(Craft::t(
            'campaign-manager',
            'Campaign {campaignId} batch failed: {errors} of {total} recipients could not be sent. Check the plugin log for details.',
            [
                'campaignId' => $this->campaignId,
                'errors' => $errors,
                'total' => $totalRecipients,
            ]
        ));
    }

    /**
     * Queue the recipients whose invitation delay has not passed as a batch
     * of their own, carrying this batch's settings, to run once the last of
     * them is eligible. Running it then checks the delay again, so it is
     * safe for the queue to run it early, for the delay to change, or for
     * another batch to send to the same recipients first.
     *
     * @param array<int, int> $waiting Seconds to wait by recipient ID
     */
    private function requeueWaiting(array $waiting): void
    {
        $wait = max($waiting);

        Queue::push(new self([
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'recipientIds' => array_keys($waiting),
            'sendSms' => $this->sendSms,
            'sendEmail' => $this->sendEmail,
            'triggeredByUserId' => $this->triggeredByUserId,
            'onlyWhileCampaignEnabled' => $this->onlyWhileCampaignEnabled,
        ]), null, $wait);

        $this->logInfo('Recipients queued again for the invitation delay', [
            'campaignId' => $this->campaignId,
            'siteId' => $this->siteId,
            'recipientCount' => count($waiting),
            'waitSeconds' => $wait,
        ]);
    }

    /**
     * Send one channel's invitation to a recipient unless another batch is
     * sending it right now or has already sent it.
     *
     * Batches can overlap on a recipient, and each loads its recipients
     * before sending, so two of them can both see the same invitation as
     * pending. The send and the send-date write that follows it therefore
     * happen under a lock for that recipient and channel, and the recipient
     * is read again from the primary database once the lock is held. A lock
     * held elsewhere is not waited for: the batch holding it is in the
     * middle of this very send. SMS and email are locked separately, so one
     * channel never holds back the other.
     *
     * @param callable(RecipientRecord): bool $send Sends the invitation to the freshly loaded recipient
     * @return string One of the OUTCOME_ constants
     */
    private function sendOncePerChannel(int $recipientId, string $channel, callable $send): string
    {
        $mutex = Craft::$app->getMutex();
        $lockName = "campaign-manager:invitation-send:{$recipientId}:{$channel}";

        if (!$mutex->acquire($lockName, 0)) {
            $this->logInfo('Invitation is being sent by another batch', [
                'recipientId' => $recipientId,
                'channel' => $channel,
            ]);

            return self::OUTCOME_SKIPPED;
        }

        try {
            /** @var RecipientRecord|null $pending */
            $pending = Craft::$app->getDb()->usePrimary(
                static fn(): ?RecipientRecord => RecipientRecord::findOne($recipientId)
            );

            if ($pending === null) {
                return self::OUTCOME_RECIPIENT_DELETED;
            }

            if (!empty($channel === 'sms' ? $pending->smsSendDate : $pending->emailSendDate)) {
                $this->logInfo('Invitation was already sent by another batch', [
                    'recipientId' => $recipientId,
                    'channel' => $channel,
                ]);

                return self::OUTCOME_SKIPPED;
            }

            // A never-sent recipient may have outlived its provisional manual
            // deadline: its first successful send still gets a full window.
            // Once either channel was sent, that shared deadline is final.
            if ($pending->hasSubmission() ||
                (($pending->smsSendDate !== null || $pending->emailSendDate !== null) && $pending->invitationIsExpired())) {
                return self::OUTCOME_SKIPPED;
            }

            return $send($pending) ? self::OUTCOME_SENT : self::OUTCOME_FAILED;
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * Whether the campaign still exists and is enabled, both overall and for
     * this batch's site, at the time the batch runs.
     */
    private function campaignIsEnabledForSite(): bool
    {
        $campaign = Campaign::find()
            ->id($this->campaignId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        return $campaign !== null && $campaign->enabled && (bool)$campaign->getEnabledForSite();
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
