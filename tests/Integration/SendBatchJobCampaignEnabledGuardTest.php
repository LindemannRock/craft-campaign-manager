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
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\RecordingEmailsService;
use lindemannrock\campaignmanager\tests\Stubs\RecordingSmsInvitationService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A send batch only refuses a campaign that was disabled after queueing when
 * the producer asked for that; batches queued without the request, including
 * ones queued before the option existed, keep sending as they always did.
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

    public function testAddingARecipientQueuesABatchThatSendsRegardlessOfTheCampaignState(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteA,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sendInvitation' => '1',
        ]);

        try {
            $response = $this->recipientsController()->actionAdd();
        } finally {
            foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
                $this->ownRecipient((int)$recipientId);
            }
        }

        self::assertTrue($response->data['success']);
        $jobs = $this->queuedJobs((int)$campaign->id);
        self::assertCount(1, $jobs);
        $batch = $jobs[0];
        self::assertInstanceOf(SendBatchJob::class, $batch);
        self::assertFalse($batch->onlyWhileCampaignEnabled);
    }

    public function testProcessingACampaignQueuesBatchesThatSendRegardlessOfTheCampaignState(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedCampaignWithMessages();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $user = $this->actAsUserRestrictedTo([$siteA], self::RECIPIENT_PERMISSIONS);

        (new ProcessCampaignJob([
            'campaignId' => (int)$campaign->id,
            'siteId' => $siteA,
            'sendSms' => false,
            'sendEmail' => true,
            'triggeredByUserId' => (int)$user->id,
        ]))->execute(Craft::$app->getQueue());

        $jobs = $this->queuedJobs((int)$campaign->id);
        self::assertCount(1, $jobs);
        $batch = $jobs[0];
        self::assertInstanceOf(SendBatchJob::class, $batch);
        self::assertSame([(int)$recipient->id], $batch->recipientIds);
        self::assertFalse($batch->onlyWhileCampaignEnabled);
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
