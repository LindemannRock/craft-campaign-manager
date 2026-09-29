<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\TestCase;
use verbb\formie\elements\Form;
use verbb\formie\events\SendNotificationEvent;
use verbb\formie\Formie;
use verbb\formie\models\Notification;
use verbb\formie\models\Settings as FormieSettings;
use verbb\formie\services\Submissions;
use yii\base\Event;

/**
 * Covers which completed Formie submissions are linked to an invited
 * recipient, and that Formie's own processing continues either way.
 *
 * @since 5.16.0
 */
final class InvitationSubmissionAssociationTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $notificationIds = [];

    /**
     * @var list<int>
     */
    private array $notifiedSubmissionIds = [];

    private ?\Closure $notificationListener = null;

    private ?bool $originalUseQueueForNotifications = null;

    protected function cleanupExternalState(): void
    {
        if ($this->notificationListener !== null) {
            Event::off(Submissions::class, Submissions::EVENT_BEFORE_SEND_NOTIFICATION, $this->notificationListener);
            $this->notificationListener = null;
        }

        if ($this->originalUseQueueForNotifications !== null) {
            /** @var FormieSettings $settings */
            $settings = Formie::$plugin->getSettings();
            $settings->useQueueForNotifications = $this->originalUseQueueForNotifications;
            $this->originalUseQueueForNotifications = null;
        }

        if ($this->notificationIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%formie_notifications}}', ['id' => $this->notificationIds])
                ->execute();

            $remaining = (new Query())
                ->from('{{%formie_notifications}}')
                ->where(['id' => $this->notificationIds])
                ->count();
            if ((int)$remaining !== 0) {
                throw new \RuntimeException('Tracked test notifications still exist after cleanup.');
            }

            $this->notificationIds = [];
        }

        $this->notifiedSubmissionIds = [];

        parent::cleanupExternalState();
    }

    public function testValidInvitationLinksTheSubmission(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testSmsInvitationCodeLinksTheSubmission(): void
    {
        [$form, $recipient] = $this->seedInvitation(['smsInvitationCode' => 'smsCode' . bin2hex(random_bytes(4))]);

        $submission = $this->submitForm($form, $recipient->smsInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testSubmissionWithoutCodeIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, null);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testSubmissionWithUnknownCodeIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, 'unknown' . bin2hex(random_bytes(4)));

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testSubmissionWithNonStringCodeIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $submission = $this->submitForm($form, null);
        $this->installInvitationRequest(['code' => [$recipient->emailInvitationCode]]);
        Formie::$plugin->getSubmissions()->onAfterSubmission(true, $submission);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testSubmissionToAnotherFormIsNotLinked(): void
    {
        [, $recipient] = $this->seedInvitation();
        $otherForm = $this->seedForm(['feedback', 'recipientName']);

        $submission = $this->submitForm($otherForm, $recipient->emailInvitationCode);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
        self::assertArrayNotHasKey('recipientName', array_filter($this->persistedSubmissionContent($submission)));
    }

    public function testSubmissionOnAnotherSiteIsNotLinked(): void
    {
        $otherSiteId = $this->secondarySiteId();
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, $recipient->emailInvitationCode, $otherSiteId);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testSubmissionPostedForTheRecipientSiteIsLinked(): void
    {
        $recipientSiteId = $this->secondarySiteId();
        [$form, $recipient] = $this->seedInvitation(['siteId' => $recipientSiteId]);

        $submission = $this->submitForm($form, $recipient->emailInvitationCode, $recipientSiteId);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testExpiredInvitationIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation([
            'invitationExpiryDate' => Db::prepareDateForDb(new DateTime('-1 hour')),
        ]);

        $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testInvitationExpiringLaterIsLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation([
            'invitationExpiryDate' => Db::prepareDateForDb(new DateTime('+1 hour')),
        ]);

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testRepeatedSubmissionsKeepTheFirstResponse(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $first = $this->submitForm($form, $recipient->emailInvitationCode, null, ['feedback' => 'First']);
        $second = $this->submitForm($form, $recipient->emailInvitationCode, null, ['feedback' => 'Second']);
        $third = $this->submitForm($form, $recipient->smsInvitationCode, null, ['feedback' => 'Third']);

        self::assertSame((int)$first->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertSame('Invited Person', $this->persistedSubmissionContent($first)['recipientName'] ?? null);
        self::assertEmpty($this->persistedSubmissionContent($second)['recipientName'] ?? null);
        self::assertEmpty($this->persistedSubmissionContent($third)['recipientName'] ?? null);
    }

    public function testExistingResponseSurvivesAnInvalidSubmission(): void
    {
        [$form, $recipient] = $this->seedInvitation();
        $otherForm = $this->seedForm(['feedback']);

        $first = $this->submitForm($form, $recipient->emailInvitationCode);
        $this->submitForm($otherForm, $recipient->emailInvitationCode);

        self::assertSame((int)$first->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testIncompleteSubmissionIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, $recipient->emailInvitationCode, isIncomplete: true);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testCompletingAMultiPageSubmissionLinksIt(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $submission = $this->submitForm($form, $recipient->emailInvitationCode, isIncomplete: true);
        $submission->isIncomplete = false;
        $this->saveTestElement($submission, false, false, false);
        Formie::$plugin->getSubmissions()->onAfterSubmission(true, $submission);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
    }

    public function testSpamSubmissionIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, $recipient->emailInvitationCode, isSpam: true);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testFailedSubmissionIsNotLinked(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $this->submitForm($form, $recipient->emailInvitationCode, success: false);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
    }

    public function testLinkingAResponseMarksTheRecipientAsUpdated(): void
    {
        [$form, $recipient] = $this->seedInvitation();
        $this->ageRecipient($recipient, '2 days');
        $submittedAt = time();

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertGreaterThanOrEqual($submittedAt, $this->persistedRecipientUpdatedAt($recipient));
        self::assertLessThanOrEqual(time(), $this->persistedRecipientUpdatedAt($recipient));
    }

    public function testRepeatedSubmissionLeavesTheLinkedRecipientUnchanged(): void
    {
        [$form, $recipient] = $this->seedInvitation();

        $first = $this->submitForm($form, $recipient->emailInvitationCode);
        $this->ageRecipient($recipient, '1 hour');
        $linkedAt = $this->persistedRecipientUpdatedAt($recipient);

        $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$first->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertSame($linkedAt, $this->persistedRecipientUpdatedAt($recipient));
    }

    public function testLatestResponseIsListedFirst(): void
    {
        $form = $this->seedForm(['feedback']);
        $campaign = $this->seedCampaign($form);
        $later = $this->seedRecipient($campaign, ['name' => 'Later respondent']);
        $earlier = $this->seedRecipient($campaign, ['name' => 'Earlier respondent']);
        $this->ageRecipient($later, '2 days');

        $this->submitForm($form, $earlier->emailInvitationCode);
        $this->ageRecipient($earlier, '1 hour');
        $this->submitForm($form, $later->emailInvitationCode);

        $responses = CampaignManager::$plugin->recipients->getWithSubmissions(
            (int)$campaign->id,
            (int)$campaign->siteId,
        );

        self::assertSame(
            [(int)$later->id, (int)$earlier->id],
            array_map(static fn($recipient): int => (int)$recipient->id, $responses),
        );
    }

    public function testNotificationsContinueAfterALinkedSubmission(): void
    {
        [$form, $recipient] = $this->seedInvitation();
        $this->captureNotifications($form);

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertSame([(int)$submission->id], $this->notifiedSubmissionIds);
    }

    public function testNotificationsContinueAfterADeclinedSubmission(): void
    {
        [$form, $recipient] = $this->seedInvitation([
            'invitationExpiryDate' => Db::prepareDateForDb(new DateTime('-1 hour')),
        ]);
        $this->captureNotifications($form);

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertNull($this->reloadRecipient($recipient)->submissionId);
        self::assertSame([(int)$submission->id], $this->notifiedSubmissionIds);
    }

    public function testNotificationsContinueForFormsWithoutEnrichmentFields(): void
    {
        $form = $this->seedForm(['feedback']);
        $recipient = $this->seedRecipient($this->seedCampaign($form));
        $this->captureNotifications($form);

        $submission = $this->submitForm($form, $recipient->emailInvitationCode);

        self::assertSame((int)$submission->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertSame([(int)$submission->id], $this->notifiedSubmissionIds);
    }

    /**
     * @param array<string, mixed> $recipientAttributes
     * @return array{Form, RecipientRecord}
     */
    private function seedInvitation(array $recipientAttributes = []): array
    {
        $form = $this->seedForm(['feedback', 'recipientName']);
        $campaign = $this->seedCampaign($form);
        $recipient = $this->seedRecipient($campaign, array_merge(['name' => 'Invited Person'], $recipientAttributes));

        return [$form, $recipient];
    }

    /**
     * Give the form an enabled notification and record which submissions
     * reach Formie's notification phase. Delivery itself is cancelled, so no
     * email is sent and no queue job is created.
     */
    private function captureNotifications(Form $form): void
    {
        $notification = new Notification();
        $notification->formId = (int)$form->id;
        $notification->name = $this->nextTestMarker('Campaign Manager test ', 'notification');
        $notification->enabled = true;
        $notification->subject = 'Test';
        $notification->to = 'notifications@example.test';
        $notification->content = '[]';

        if (!Formie::$plugin->getNotifications()->saveNotification($notification, false) || $notification->id === null) {
            throw new \RuntimeException('Test notification failed to save.');
        }
        $this->notificationIds[] = (int)$notification->id;

        // Formie memoizes notifications per request, so hand the form the
        // list a fresh request would load
        $form->setNotifications([$notification]);

        /** @var FormieSettings $settings */
        $settings = Formie::$plugin->getSettings();
        $this->originalUseQueueForNotifications = $settings->useQueueForNotifications;
        $settings->useQueueForNotifications = false;

        $this->notificationListener = function(SendNotificationEvent $event): void {
            $this->notifiedSubmissionIds[] = (int)$event->submission->id;
            $event->isValid = false;
        };
        Event::on(Submissions::class, Submissions::EVENT_BEFORE_SEND_NOTIFICATION, $this->notificationListener);
    }
}
