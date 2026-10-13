<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Query;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\RecordingEmailsService;
use lindemannrock\campaignmanager\tests\Stubs\RecordingSmsInvitationService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * A batch queued by adding a recipient, importing recipients or running the
 * campaign sends nothing once the campaign has been disabled for its site,
 * however long it waited in the queue. Only a batch stored before the guard
 * existed keeps sending as it always did.
 *
 * @since 5.16.0
 */
#[CoversClass(SendBatchJob::class)]
final class SendBatchJobCampaignEnabledGuardTest extends SiteRestrictedUserTestCase
{
    private const RECIPIENT_PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
    ];

    public function testABatchSendsRegardlessOfTheCampaignStateUnlessAsked(): void
    {
        self::assertFalse((new SendBatchJob())->onlyWhileCampaignEnabled);
    }

    public function testABatchQueuedBeforeTheOptionExistedStillSendsWhenTheCampaignIsDisabled(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $user = $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        $emails = new RecordingEmailsService();
        $this->swapPluginComponent('campaign-manager', 'emails', $emails);
        $this->swapPluginComponent('campaign-manager', 'recipients', new RecordingSmsInvitationService());

        $queued = new SendBatchJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteA,
            'recipientIds' => [(int)$recipient->id],
            'sendSms' => false,
            'sendEmail' => true,
            'triggeredByUserId' => (int)$user->id,
            'onlyWhileCampaignEnabled' => true,
        ]);
        $legacy = $this->withoutTheOption(serialize($queued));
        self::assertStringNotContainsString('onlyWhileCampaignEnabled', $legacy);
        $restored = unserialize($legacy);
        self::assertInstanceOf(SendBatchJob::class, $restored);

        $this->disableOwnedCampaignForSite($campaign, $siteA);
        $restored->execute(Craft::$app->getQueue());

        self::assertSame([(int)$recipient->id], $emails->sentToRecipientIds);
        self::assertNotNull($this->latestOwnedLog('invitations_sent_batch'));
        self::assertFalse($restored->onlyWhileCampaignEnabled);
    }

    public function testABatchQueuedByAddingARecipientSendsNothingOnceTheCampaignIsDisabledForItsSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        [$emails, $sms] = $this->captureDelivery();

        $recipientId = $this->addRecipientWithSendInvitation($campaign, $siteA);
        $batch = $this->theOnlyQueuedBatch((int)$campaign->id);
        self::assertSame([$recipientId], $batch->recipientIds);
        $rowBefore = $this->persistedRecipientRow($recipientId);
        $logsBefore = $this->ownedActivityLogCount();

        $this->disableOwnedCampaignForSite($campaign, $siteA);
        $batch->execute(Craft::$app->getQueue());

        self::assertTrue($batch->onlyWhileCampaignEnabled, 'A batch queued by adding a recipient only sends while the campaign is enabled.');
        self::assertSame([], $emails->sentToRecipientIds, 'No email may be sent once the campaign is disabled.');
        self::assertSame([], $sms->sentToRecipientIds, 'No SMS may be sent once the campaign is disabled.');
        self::assertSame($rowBefore, $this->persistedRecipientRow($recipientId), 'No send date is written.');
        self::assertNull($this->latestOwnedLog('invitations_sent_batch'));
        self::assertSame($logsBefore, $this->ownedActivityLogCount());
    }

    public function testABatchQueuedByAddingARecipientSendsWhileTheCampaignStaysEnabled(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        [$emails, $sms] = $this->captureDelivery();

        $recipientId = $this->addRecipientWithSendInvitation($campaign, $siteA, '+96597176606');
        $batch = $this->theOnlyQueuedBatch((int)$campaign->id);

        $batch->execute(Craft::$app->getQueue());

        self::assertSame([$recipientId], $emails->sentToRecipientIds);
        self::assertSame([$recipientId], $sms->sentToRecipientIds);
        self::assertNotNull($this->latestOwnedLog('invitations_sent_batch'));
    }

    public function testABatchQueuedByACampaignRunSendsNothingOnceTheCampaignIsDisabledForItsSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA, ['sms' => '+96597176606']);
        $user = $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        [$emails, $sms] = $this->captureDelivery();

        $this->runCampaign($campaign, $siteA, (int)$user->id);
        $batch = $this->theOnlyQueuedBatch((int)$campaign->id);
        self::assertSame([(int)$recipient->id], $batch->recipientIds);
        self::assertSame((int)$user->id, $batch->triggeredByUserId);
        $rowBefore = $this->persistedRecipientRow((int)$recipient->id);
        $logsBefore = $this->ownedActivityLogCount();

        $this->disableOwnedCampaignForSite($campaign, $siteA);
        $batch->execute(Craft::$app->getQueue());

        self::assertTrue($batch->onlyWhileCampaignEnabled, 'A batch queued by a campaign run only sends while the campaign is enabled.');
        self::assertSame([], $emails->sentToRecipientIds);
        self::assertSame([], $sms->sentToRecipientIds);
        self::assertSame($rowBefore, $this->persistedRecipientRow((int)$recipient->id));
        self::assertNull($this->latestOwnedLog('invitations_sent_batch'));
        self::assertSame($logsBefore, $this->ownedActivityLogCount());
    }

    public function testABatchQueuedByACampaignRunSendsWhileTheCampaignStaysEnabled(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA, ['sms' => '+96597176606']);
        $user = $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        [$emails, $sms] = $this->captureDelivery();

        $this->runCampaign($campaign, $siteA, (int)$user->id);
        $batch = $this->theOnlyQueuedBatch((int)$campaign->id);

        $batch->execute(Craft::$app->getQueue());

        self::assertSame([(int)$recipient->id], $emails->sentToRecipientIds);
        self::assertSame([(int)$recipient->id], $sms->sentToRecipientIds);
        self::assertNotNull($this->latestOwnedLog('invitations_sent_batch'));
    }

    public function testARunBatchHeldBackByTheInvitationDelayKeepsTheGuardWhenItIsQueuedAgain(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $campaign->invitationDelayPeriod = 'P1D';
        $this->saveTestElement($campaign, false, true, false);
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $user = $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        [$emails, $sms] = $this->captureDelivery();

        $this->runCampaign($campaign, $siteA, (int)$user->id);
        $waiting = $this->theOnlyQueuedBatch((int)$campaign->id);
        $waiting->execute(Craft::$app->getQueue());

        self::assertSame([], $emails->sentToRecipientIds, 'The recipient has not waited the delay yet.');
        self::assertSame([], $sms->sentToRecipientIds);
        $jobs = $this->queuedJobs((int)$campaign->id);
        self::assertCount(2, $jobs, 'The recipient is queued again after the first batch.');
        $requeued = $jobs[1];
        self::assertInstanceOf(SendBatchJob::class, $requeued);
        self::assertSame([(int)$recipient->id], $requeued->recipientIds);
        self::assertTrue($requeued->onlyWhileCampaignEnabled, 'The guard travels with the recipients that wait.');
    }

    /**
     * Post the Add Recipient form with "Send Invitation" ticked and return
     * the recipient it created.
     */
    private function addRecipientWithSendInvitation(Campaign $campaign, int $siteId, ?string $sms = null): int
    {
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';
        $body = [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteId,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sendInvitation' => '1',
        ];
        if ($sms !== null) {
            $body['sms'] = $sms;
            $body['smsCountry'] = 'KW';
        }
        $this->request('POST', [], $body);

        $created = [];
        try {
            $response = $this->recipientsController()->actionAdd();
            self::assertTrue($response->data['success']);
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $created[] = (int)$recipientId;
                $this->ownRecipient((int)$recipientId);
            }
        }
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * Run the campaign for the site as a worker would.
     */
    private function runCampaign(Campaign $campaign, int $siteId, int $userId): void
    {
        (new ProcessCampaignJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteId,
            'sendSms' => true,
            'sendEmail' => true,
            'triggeredByUserId' => $userId,
        ]))->execute(Craft::$app->getQueue());
    }

    private function theOnlyQueuedBatch(int $campaignId): SendBatchJob
    {
        $jobs = $this->queuedJobs($campaignId);
        self::assertCount(1, $jobs);
        self::assertInstanceOf(SendBatchJob::class, $jobs[0]);

        return $jobs[0];
    }

    /**
     * Capture email and SMS delivery at the plugin's own services, so
     * nothing reaches the mailer or SMS Manager.
     *
     * @return array{RecordingEmailsService, RecordingSmsInvitationService}
     */
    private function captureDelivery(): array
    {
        $emails = new RecordingEmailsService();
        $sms = new RecordingSmsInvitationService();
        $this->swapPluginComponent('campaign-manager', 'emails', $emails);
        $this->swapPluginComponent('campaign-manager', 'recipients', $sms);

        return [$emails, $sms];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedRecipientRow(int $recipientId): array
    {
        $row = (new Query())->from(RecipientRecord::tableName())->where(['id' => $recipientId])->one();
        if ($row === false) {
            throw new RuntimeException("Recipient {$recipientId} does not exist.");
        }

        return $row;
    }

    private function seedCampaignWithMessages(): Campaign
    {
        $campaign = $this->seedOwnedCampaign();
        $campaign->emailInvitationMessage = 'Please take part.';
        $campaign->smsInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * The serialized form of a batch as it was stored before the option
     * existed: the property is absent and the property count is one lower.
     */
    private function withoutTheOption(string $serialized): string
    {
        $stripped = (string)preg_replace('/s:\d+:"onlyWhileCampaignEnabled";b:[01];/', '', $serialized, -1, $count);
        if ($count === 0) {
            return $serialized;
        }

        return (string)preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+)(:\{)/',
            static fn(array $match): string => $match[1] . ((int)$match[2] - 1) . $match[3],
            $stripped,
        );
    }
}
