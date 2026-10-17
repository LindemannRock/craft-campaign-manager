<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\campaignmanager\tests\Stubs\RecordingProgressQueue;
use lindemannrock\campaignmanager\tests\Support\ProviderTransportTestCase;
use lindemannrock\campaignmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\services\TransportService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Shared provider holds, interleaved jobs and recovery through real dispatch.
 *
 * @since 5.16.0
 */
final class CampaignSharedTransportTest extends ProviderTransportTestCase
{
    public function testOptionalCustomProviderContractAndLegacyProviderRemainCompatible(): void
    {
        $providers = new \lindemannrock\smsmanager\services\ProvidersService();
        $providers->getProviderTypes();
        $providers->registerProviderType(\lindemannrock\campaignmanager\tests\Stubs\GuardedCampaignProvider::class);
        $this->swapPluginComponent('sms-manager', 'providers', $providers);
        $legacy = $this->seedDeliverableCampaign();
        self::assertNull(CampaignManager::$plugin->sms->getTransportHoldResult($legacy->senderHandle));
        self::assertNull(CampaignManager::$plugin->sms->getTransportHoldNotice($legacy->senderHandle));
        $campaign = $this->transportCampaign('campaign-synthetic', ['account' => $this->credential]);
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame([['smsSent' => 0, 'emailSent' => 2, 'errors' => 0]], $this->batchCounts($campaign));
        self::assertNotNull(CampaignManager::$plugin->sms->getTransportHoldNotice($campaign->senderHandle));
        self::assertSame('account', CampaignManager::$plugin->sms->getTransportHoldResult($campaign->senderHandle)['failureScope']);
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        self::assertCount(0, $this->queuedJobs((int)$campaign->id));
    }

    public function testManualReleaseSendsNothingAndExplicitRunIncludesRemainingAndNewRecipients(): void
    {
        $campaign = $this->mppCampaign();
        $sent = $this->seedPendingRecipient($campaign);
        $pending = $this->seedPendingRecipient($campaign);
        $this->http('OK,smsid:synthetic-one,mobiles:1', 'error,user is inactive');
        $this->runBatch($this->batch($campaign, [$sent, $pending]));
        self::assertCount(0, $this->queuedJobs((int)$campaign->id));
        $before = TransportIsolation::$interceptedRequests;
        $user = $this->actAsUserRestrictedTo([$this->siteId], []);
        $context = $this->guard->contextForProvider($this->provider);
        $hold = $this->guard->status($context)['holds'][0];
        try {
            self::assertTrue($this->guard->release($context, $hold['lane'], $hold['token'], (int)$user->id));
            self::assertSame($before, TransportIsolation::$interceptedRequests);
            self::assertCount(0, $this->queuedJobs((int)$campaign->id));
            $added = $this->seedPendingRecipient($campaign);
            (new ProcessCampaignJob(['campaignId' => (int)$campaign->id, 'siteId' => $this->siteId]))->execute(Craft::$app->getQueue());
            $queued = $this->queuedJobs((int)$campaign->id);
            self::assertCount(1, $queued);
            self::assertSame([(int)$pending->id, (int)$added->id], $queued[0]->recipientIds);
            $this->http('OK,smsid:synthetic-two,mobiles:1', 'OK,smsid:synthetic-three,mobiles:1');
            $this->runBatch($queued[0]);
            self::assertSame($before + 2, TransportIsolation::$interceptedRequests);
            self::assertCount(3, $this->sentTo(self::EMAIL));
            foreach ([$sent, $pending, $added] as $recipient) {
                self::assertNotNull($this->sendDate($recipient, self::SMS));
            }
        } finally {
            $ids = (new Query())->select('id')->from(TransportService::RELEASE_TABLE)->where(['releasedBy' => $user->id])->column();
            if ($ids !== []) {
                Craft::$app->getDb()->createCommand()->delete(TransportService::RELEASE_TABLE, ['id' => $ids])->execute();
            }
        }
    }

    public function testExistingHoldWithOnlyInvitationWaitingRecipientsRetainsOneSmsContinuation(): void
    {
        $campaign = $this->mppCampaign();
        $recipient = $this->seedPendingRecipient($campaign);
        $campaign->invitationDelayPeriod = 'PT1H';
        $this->saveTestElement($campaign, false, true, false);
        $this->hold('ip-blocked', 120);
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, [$recipient], ['onlyWhileCampaignEnabled' => true]));
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(2, $queued);
        self::assertFalse($queued[0]['job']->sendSms);
        self::assertTrue($queued[0]['job']->sendEmail);
        self::assertTrue($queued[1]['job']->sendSms);
        self::assertFalse($queued[1]['job']->sendEmail);
        self::assertTrue($queued[1]['job']->onlyWhileCampaignEnabled);
        self::assertSame([(int)$recipient->id], $queued[1]['job']->recipientIds);
        self::assertGreaterThanOrEqual(120, $queued[1]['delay']);
    }

    public static function firstFailures(): iterable
    {
        yield 'manual account' => [401, '{"code":20003}', false];
        yield 'timed account' => [429, '{"code":20429}', true];
        yield 'timed transport' => [503, '<p>synthetic-private-response</p>', true];
    }

    #[DataProvider('firstFailures')]
    public function testFirstFailureAndAnotherJobRespectTheHoldThenRecover(int $status, string $body, bool $timed): void
    {
        $settings = ['accountSid' => $this->credential, 'authToken' => 'synthetic-token'];
        $campaign = $this->transportCampaign('twilio', $settings);
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        TransportIsolation::$responses = new MockHandler([new Response(201, [], '{"sid":"SMsynthetic"}'), new Response($status, [], $body)]);
        $before = TransportIsolation::$interceptedRequests;
        $batch = $this->batch($campaign, $recipients, ['onlyWhileCampaignEnabled' => true]);
        $queue = new RecordingProgressQueue();
        $batch->execute($queue);
        self::assertSame([33, 67, 100], $queue->progress);
        self::assertSame($before + 2, TransportIsolation::$interceptedRequests);
        self::assertSame([['smsSent' => 1, 'emailSent' => 3, 'errors' => 0]], $this->batchCounts($campaign));
        $continuations = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount($timed ? 1 : 0, $continuations);
        if ($timed) {
            self::assertSame(60, $continuations[0]['delay']);
            self::assertSame([(int)$recipients[1]->id, (int)$recipients[2]->id], $continuations[0]['job']->recipientIds);
            self::assertTrue($continuations[0]['job']->onlyWhileCampaignEnabled);
            self::assertFalse($continuations[0]['job']->sendEmail);
        }
        // A separately queued campaign using the same account observes the hold.
        $other = $this->transportCampaign('twilio', $settings);
        $otherRecipient = $this->seedPendingRecipient($other);
        $this->runBatch($this->batch($other, [$otherRecipient]));
        self::assertSame($before + 2, TransportIsolation::$interceptedRequests);
        self::assertNull($this->sendDate($otherRecipient, self::SMS));
        self::assertNotNull($this->sendDate($otherRecipient, self::EMAIL));
        self::assertSame([['smsSent' => 0, 'emailSent' => 1, 'errors' => 0]], $this->batchCounts($other));
        if ($timed) {
            $this->guard->time += 60;
            DateTimeHelper::pause(new \DateTime('@' . $this->guard->time));
            try {
                TransportIsolation::$responses = new MockHandler([new Response(201, [], '{"sid":"SMtwo"}'), new Response(201, [], '{"sid":"SMthree"}')]);
                $this->runBatch(unserialize(serialize($continuations[0]['job'])));
                // An overlapping old job skips every recorded success.
                $this->runBatch($batch);
                self::assertSame($before + 4, TransportIsolation::$interceptedRequests);
                foreach ($recipients as $recipient) {
                    self::assertNotNull($this->sendDate($recipient, self::SMS));
                }
                self::assertNull(CampaignManager::$plugin->sms->getTransportHoldNotice($campaign->senderHandle));
            } finally {
                DateTimeHelper::resume();
            }
        }
    }

    public function testAccountHoldLeavesAnotherAccountEligibleAndTransportHoldLeavesOtherProviderEligible(): void
    {
        $first = $this->transportCampaign('twilio', ['accountSid' => $this->credential, 'authToken' => 'synthetic-token']);
        $this->hold('account-unavailable');
        $second = $this->transportCampaign('twilio', ['accountSid' => 'synthetic-other-account', 'authToken' => 'synthetic-token']);
        $secondRecipient = $this->seedPendingRecipient($second);
        TransportIsolation::$responses = new MockHandler([new Response(201, [], '{"sid":"SMother"}')]);
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->channelBatch($second, [$secondRecipient], self::SMS));
        self::assertNotNull($this->sendDate($secondRecipient, self::SMS));
        self::assertNotNull(CampaignManager::$plugin->sms->getTransportHoldResult($first->senderHandle));
        $this->hold('provider-unavailable', 60);
        self::assertSame('transport', CampaignManager::$plugin->sms->getTransportHoldResult($first->senderHandle)['failureScope']);
        $firstRecipient = $this->seedPendingRecipient($first);
        $this->runBatch($this->channelBatch($first, [$firstRecipient], self::SMS));
        self::assertSame($before + 1, TransportIsolation::$interceptedRequests);
        $mpp = $this->mppCampaign();
        $mppRecipient = $this->seedPendingRecipient($mpp);
        $this->http('OK,smsid:synthetic-id,mobiles:1');
        $this->runBatch($this->channelBatch($mpp, [$mppRecipient], self::SMS));
        self::assertNotNull($this->sendDate($mppRecipient, self::SMS));
        self::assertSame($before + 2, TransportIsolation::$interceptedRequests);
    }

    public function testDevelopmentLaneUsesProviderOwnedIdentityAndFallback(): void
    {
        $main = $this->mppCampaign();
        $this->hold('account-unavailable');
        $dev = $this->transportCampaign('mpp-sms', ['apiKey' => $this->credential, 'devApiKey' => 'synthetic-dev', 'apiUrl' => 'https://mpp.example.test/send'], true);
        $recipient = $this->seedPendingRecipient($dev);
        $this->http('OK,smsid:synthetic-dev,mobiles:1');
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->channelBatch($dev, [$recipient], self::SMS));
        self::assertSame($before + 1, TransportIsolation::$interceptedRequests);
        self::assertNotNull($this->sendDate($recipient, self::SMS));
        $fallback = $this->transportCampaign('mpp-sms', ['apiKey' => $this->credential, 'devApiKey' => '', 'apiUrl' => 'https://mpp.example.test/send'], true);
        self::assertNotNull(CampaignManager::$plugin->sms->getTransportHoldResult($fallback->senderHandle));
        self::assertNotNull(CampaignManager::$plugin->sms->getTransportHoldResult($main->senderHandle));
        $heldRecipient = $this->seedPendingRecipient($fallback);
        $this->runBatch($this->channelBatch($fallback, [$heldRecipient], self::SMS));
        self::assertNull($this->sendDate($heldRecipient, self::SMS));
        self::assertSame($before + 1, TransportIsolation::$interceptedRequests);
    }

    public function testHoldCreatedAfterPreflightIsStillHonoredByActualSendResult(): void
    {
        $campaign = $this->mppCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        $this->guard->afterStatus = function(): void {
            $this->hold('ip-blocked', 120);
        };
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        self::assertSame([['smsSent' => 0, 'emailSent' => 2, 'errors' => 0]], $this->batchCounts($campaign));
        $queued = $this->queuedJobsWithDelay((int)$campaign->id);
        self::assertCount(1, $queued);
        self::assertSame(120, $queued[0]['delay']);
        self::assertSame(array_map(static fn($recipient): int => (int)$recipient->id, $recipients), $queued[0]['job']->recipientIds);
    }

    public function testInterleavedJobSeesInFlightAdmissionAndDoesNotDuplicateDelivery(): void
    {
        $campaign = $this->mppCampaign();
        $recipients = [$this->seedPendingRecipient($campaign), $this->seedPendingRecipient($campaign)];
        TransportIsolation::$responses = new MockHandler([
            function() use ($campaign, $recipients): Response {
                $this->runBatch($this->batch($campaign, $recipients));
                return new Response(200, [], 'OK,smsid:synthetic-one,mobiles:1');
            },
            new Response(200, [], 'OK,smsid:synthetic-two,mobiles:1'),
        ]);
        $before = TransportIsolation::$interceptedRequests;
        $this->runBatch($this->batch($campaign, $recipients));
        self::assertSame($before + 2, TransportIsolation::$interceptedRequests);
        self::assertCount(2, $this->sentTo(self::EMAIL));
        self::assertCount(0, $this->queuedJobs((int)$campaign->id));
        foreach ($recipients as $recipient) {
            self::assertNotNull($this->sendDate($recipient, self::SMS));
            $this->assertDeliveryLockIsFree($recipient, self::SMS);
        }
    }
}
