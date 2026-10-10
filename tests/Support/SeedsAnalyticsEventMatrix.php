<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use RuntimeException;
use verbb\formie\elements\Form;

/**
 * One campaign whose recipients were created, invited, opened and answered at
 * known moments around the "last month" date range, so every analytics
 * surface can be checked against the same expected totals.
 *
 * The range is "last month" because both of its edges are local midnights,
 * which makes the start-inclusive and end-exclusive edges testable.
 *
 * Used by test classes that extend {@see SiteRestrictedUserTestCase}.
 *
 * @since 5.16.0
 */
trait SeedsAnalyticsEventMatrix
{
    protected const MATRIX_RANGE = 'lastMonth';

    /**
     * What the "last month" range must report for the matrix.
     */
    protected const MATRIX_TOTALS = [
        'totalRecipients' => 3,
        'emailsSent' => 4,
        'smsSent' => 2,
        'emailsOpened' => 2,
        'smsOpened' => 2,
        'submissions' => 2,
        'expired' => 1,
        'totalSent' => 6,
        'totalOpened' => 4,
        'uniqueInvited' => 5,
        'uniqueOpened' => 3,
    ];

    /**
     * What "all time" must report for the matrix.
     */
    protected const MATRIX_ALL_TIME_TOTALS = [
        'totalRecipients' => 13,
        'emailsSent' => 10,
        'smsSent' => 4,
        'emailsOpened' => 4,
        'smsOpened' => 4,
        'submissions' => 4,
        'expired' => 2,
        'totalSent' => 14,
        'totalOpened' => 8,
        'uniqueInvited' => 11,
        'uniqueOpened' => 5,
    ];

    /**
     * Index of the daily chart bucket that holds the "inside" moment, counted
     * from the first day of last month.
     */
    protected const MATRIX_INSIDE_DAY_INDEX = 10;

    /**
     * The moments the matrix is built around, all in UTC.
     *
     * @return array{start: DateTime, end: DateTime, before: DateTime, inside: DateTime, lastSecond: DateTime, after: DateTime, justBeforeStart: DateTime}
     */
    protected function matrixInstants(): array
    {
        $bounds = DateRangeHelper::getBounds(self::MATRIX_RANGE);
        if ($bounds['start'] === null || $bounds['end'] === null) {
            throw new RuntimeException('The matrix range must have a start and an end.');
        }

        $start = $bounds['start'];
        $end = $bounds['end'];
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $after = (clone $now)->modify('-1 minute');
        if ($after < $end) {
            // The month has just begun; the exclusive end itself is outside the range.
            $after = clone $end;
        }

        return [
            'start' => $start,
            'end' => $end,
            'before' => (clone $start)->modify('-20 days'),
            'inside' => (clone $start)->modify('+' . self::MATRIX_INSIDE_DAY_INDEX . ' days +12 hours'),
            'lastSecond' => (clone $end)->modify('-1 second'),
            'after' => $after,
            'justBeforeStart' => (clone $start)->modify('-1 second'),
        ];
    }

    /**
     * Number of daily buckets the "last month" charts must have.
     */
    protected function matrixDayCount(): int
    {
        $instants = $this->matrixInstants();
        $tz = new DateTimeZone(Craft::$app->getTimeZone());

        return (int)(clone $instants['start'])->setTimezone($tz)->format('t');
    }

    /**
     * Seed the matrix on one site and return the campaign with its recipients
     * keyed by what they represent.
     *
     * @return array{campaign: Campaign, form: Form, recipients: array<string, RecipientRecord>}
     */
    protected function seedAnalyticsEventMatrix(int $siteId): array
    {
        $t = $this->matrixInstants();
        $campaign = $this->seedOwnedCampaign();
        $form = Form::find()->id($campaign->formId)->one();
        if (!$form instanceof Form) {
            throw new RuntimeException('The matrix campaign has no form.');
        }

        $recipients = [];
        $responses = [];

        $spec = [
            // Created long before the range; everything happened inside it.
            'oldEverything' => ['created' => 'before', 'emailSent' => 'inside', 'smsSent' => 'inside', 'emailOpened' => 'inside', 'smsOpened' => 'inside', 'responded' => 'inside'],
            // Created before the range; emailed, opened and answered inside it.
            'oldEmailOnly' => ['created' => 'before', 'emailSent' => 'inside', 'emailOpened' => 'inside', 'responded' => 'inside'],
            // Created before the range; texted and opened inside it.
            'oldSmsOnly' => ['created' => 'before', 'smsSent' => 'inside', 'smsOpened' => 'inside'],
            // Created before the range; everything also happened before it.
            'oldOutside' => ['created' => 'before', 'emailSent' => 'before', 'smsSent' => 'before', 'emailOpened' => 'before', 'smsOpened' => 'before', 'responded' => 'before'],
            // Created inside the range; nothing else happened yet.
            'newQuiet' => ['created' => 'inside'],
            // Created inside the range; invited and opened after it ended.
            'newLater' => ['created' => 'inside', 'emailSent' => 'after', 'smsSent' => 'after', 'emailOpened' => 'after', 'smsOpened' => 'after'],
            // Created inside the range; emailed and answered after it ended.
            'newRespondedLater' => ['created' => 'inside', 'emailSent' => 'after', 'responded' => 'after'],
            // Emailed exactly at the first moment of the range.
            'sentAtStart' => ['created' => 'before', 'emailSent' => 'start'],
            // Emailed at the last moment of the range.
            'sentAtLastSecond' => ['created' => 'before', 'emailSent' => 'lastSecond'],
            // Emailed exactly at the exclusive end of the range.
            'sentAtEnd' => ['created' => 'before', 'emailSent' => 'end'],
            // Created one second before the range began.
            'createdJustBefore' => ['created' => 'justBeforeStart'],
            // Invited before the range; the invitation expired inside it, unanswered.
            'expiredInside' => ['created' => 'before', 'emailSent' => 'before', 'expires' => 'inside'],
            // Invited before the range; the invitation expired after it, unanswered.
            'expiredLater' => ['created' => 'before', 'emailSent' => 'before', 'expires' => 'after'],
        ];

        foreach ($spec as $key => $events) {
            $attributes = [
                'dateCreated' => Db::prepareDateForDb($t[$events['created']]),
                'sms' => '+971500000' . str_pad((string)count($recipients), 3, '0', STR_PAD_LEFT),
            ];
            foreach (['emailSent' => 'emailSendDate', 'smsSent' => 'smsSendDate', 'emailOpened' => 'emailOpenDate', 'smsOpened' => 'smsOpenDate', 'expires' => 'invitationExpiryDate'] as $event => $column) {
                if (isset($events[$event])) {
                    $attributes[$column] = Db::prepareDateForDb($t[$events[$event]]);
                }
            }

            $recipient = $this->seedOwnedRecipient($campaign, $siteId, $attributes);
            $this->assertMatrixRecipientCreatedAt($recipient, $t[$events['created']]);
            $recipients[$key] = $recipient;

            if (isset($events['responded'])) {
                $responses[$key] = $t[$events['responded']];
            }
        }

        foreach ($responses as $key => $respondedAt) {
            $recipient = $recipients[$key];
            $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId);
            if ($this->reloadRecipient($recipient)->submissionId !== (int)$submission->id) {
                throw new RuntimeException("The matrix response for {$key} was not linked to its recipient.");
            }
            $this->ageSubmission($submission, $respondedAt);
            $recipients[$key] = $this->reloadRecipient($recipient);
        }

        return ['campaign' => $campaign, 'form' => $form, 'recipients' => $recipients];
    }

    /**
     * The persisted creation time must be the one the matrix asked for, or
     * every expectation built on it would be meaningless.
     */
    private function assertMatrixRecipientCreatedAt(RecipientRecord $recipient, DateTime $expected): void
    {
        // Craft hands the stored UTC string back; the record does not convert dateCreated itself.
        $stored = DateTimeHelper::toDateTime($this->reloadRecipient($recipient)->dateCreated);
        if ($stored === false || $stored->getTimestamp() !== $expected->getTimestamp()) {
            throw new RuntimeException("Matrix recipient {$recipient->id} was not created at the requested moment.");
        }
    }
}
