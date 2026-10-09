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
use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\TestCase;
use RuntimeException;

/**
 * Covers what opening an invitation records: the channel whose code was
 * opened gets its first-open time once, later and overlapping opens leave
 * it alone, and a recipient whose two invitations share one code counts as
 * opened on both channels.
 *
 * @since 5.16.0
 */
final class InvitationOpenAttributionTest extends TestCase
{
    public function testEmailCodeOpenRecordsOnlyTheEmailFirstOpen(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));

        $opened = $this->open((string)$recipient->emailInvitationCode);

        self::assertNotNull($opened->emailOpenDate);
        self::assertNull($opened->smsOpenDate, 'An email open must not count as an SMS open.');
    }

    public function testSmsCodeOpenRecordsOnlyTheSmsFirstOpen(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));

        $opened = $this->open((string)$recipient->smsInvitationCode);

        self::assertNotNull($opened->smsOpenDate);
        self::assertNull($opened->emailOpenDate, 'An SMS open must not count as an email open.');
    }

    public function testSharedCodeOpenRecordsBothChannelsOnce(): void
    {
        $recipient = $this->seedSharedCodeRecipient();

        $opened = $this->open((string)$recipient->emailInvitationCode);

        self::assertNotNull($opened->emailOpenDate);
        self::assertNotNull($opened->smsOpenDate);
        self::assertSame($opened->emailOpenDate->getTimestamp(), $opened->smsOpenDate->getTimestamp());
    }

    public function testRepeatedOpenChangesNothing(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $code = (string)$recipient->emailInvitationCode;
        $this->open($code);
        $this->ageOpenDate($recipient, 'emailOpenDate', '1 hour');
        $before = $this->persistedRow($recipient);

        $this->open($code);

        self::assertSame($before, $this->persistedRow($recipient), 'A repeated open must not change the recipient row.');
    }

    public function testRepeatedSharedCodeOpenKeepsBothFirstOpenTimes(): void
    {
        $recipient = $this->seedSharedCodeRecipient();
        $code = (string)$recipient->emailInvitationCode;
        $this->open($code);
        $this->ageOpenDate($recipient, 'emailOpenDate', '2 hours');
        $this->ageOpenDate($recipient, 'smsOpenDate', '1 hour');
        $before = $this->persistedRow($recipient);

        $this->open($code);

        self::assertSame($before, $this->persistedRow($recipient));
    }

    public function testOverlappingOpensKeepTheWinningFirstOpenTime(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $code = (string)$recipient->emailInvitationCode;
        $recipients = CampaignManager::$plugin->recipients;

        // Two requests resolve the unopened recipient before either records the open.
        $winner = $recipients->getRecipientByInvitationCode($code);
        $loser = $recipients->getRecipientByInvitationCode($code);
        self::assertInstanceOf(RecipientRecord::class, $winner);
        self::assertInstanceOf(RecipientRecord::class, $loser);
        self::assertNull($loser->emailOpenDate);

        $recipients->markAsOpened($winner);
        $this->ageOpenDate($recipient, 'emailOpenDate', '1 hour');
        $firstOpen = $this->reloadRecipient($recipient)->emailOpenDate;
        self::assertNotNull($firstOpen);

        $recipients->markAsOpened($loser);

        self::assertSame($firstOpen->getTimestamp(), $this->reloadRecipient($recipient)->emailOpenDate?->getTimestamp());
        self::assertSame($firstOpen->getTimestamp(), $loser->emailOpenDate?->getTimestamp(), 'The losing request sees the winning first open.');
        self::assertNull($this->reloadRecipient($recipient)->smsOpenDate);
    }

    public function testOpeningTheOtherChannelLaterKeepsTheEarlierFirstOpen(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $this->open((string)$recipient->emailInvitationCode);
        $this->ageOpenDate($recipient, 'emailOpenDate', '1 hour');
        $emailFirstOpen = $this->reloadRecipient($recipient)->emailOpenDate;
        self::assertNotNull($emailFirstOpen);

        $opened = $this->open((string)$recipient->smsInvitationCode);

        self::assertSame($emailFirstOpen->getTimestamp(), $opened->emailOpenDate?->getTimestamp());
        self::assertNotNull($opened->smsOpenDate);
        self::assertGreaterThan($emailFirstOpen->getTimestamp(), $opened->smsOpenDate->getTimestamp());
    }

    public function testOpenWithoutAResolvedCodeIsNotAttributedToEitherChannel(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $before = $this->persistedRow($recipient);

        CampaignManager::$plugin->recipients->markAsOpened($this->reloadRecipient($recipient));

        self::assertSame($before, $this->persistedRow($recipient), 'Without the opened code the channel is unknown, so nothing may be recorded.');
    }

    public function testOpenWithoutAResolvedCodeStillRecordsASharedCodeOnBothChannels(): void
    {
        $recipient = $this->seedSharedCodeRecipient();

        CampaignManager::$plugin->recipients->markAsOpened($this->reloadRecipient($recipient));

        $opened = $this->reloadRecipient($recipient);
        self::assertNotNull($opened->emailOpenDate);
        self::assertNotNull($opened->smsOpenDate);
    }

    public function testExpiredAndAnsweredInvitationsStillResolveByCode(): void
    {
        $form = $this->seedForm(['feedback']);
        $campaign = $this->seedCampaign($form);
        $expired = $this->seedRecipient($campaign, ['invitationExpiryDate' => new DateTime('-1 day')]);
        $answered = $this->seedRecipient($campaign);
        $this->submitForm($form, (string)$answered->emailInvitationCode);
        $recipients = CampaignManager::$plugin->recipients;

        $resolvedExpired = $recipients->getRecipientByInvitationCode((string)$expired->smsInvitationCode);
        $resolvedAnswered = $recipients->getRecipientByInvitationCode((string)$answered->emailInvitationCode);

        self::assertSame((int)$expired->id, (int)$resolvedExpired?->id);
        self::assertTrue($resolvedExpired->invitationIsExpired());
        self::assertSame((int)$answered->id, (int)$resolvedAnswered?->id);
        self::assertTrue($resolvedAnswered->hasSubmission());
    }

    public function testInvitationTemplateRecordsTheOpenOnlyForOpenInvitations(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/invite.twig');

        $resolve = strpos($template, 'recipients.getRecipientByInvitationCode(invitationCode)');
        $answered = strpos($template, 'recipient.hasSubmission()');
        $expired = strpos($template, 'recipient.invitationIsExpired()');
        $open = strpos($template, 'recipients.markAsOpened(recipient)');

        self::assertNotFalse($resolve);
        self::assertNotFalse($answered);
        self::assertNotFalse($expired);
        self::assertNotFalse($open);
        self::assertLessThan($answered, $resolve, 'The recipient is resolved by the opened code before any check.');
        self::assertLessThan($open, $answered, 'An answered invitation shows its message instead of recording an open.');
        self::assertLessThan($open, $expired, 'An expired invitation shows its message instead of recording an open.');
        self::assertSame(1, substr_count($template, 'markAsOpened('));
    }

    public function testAnalyticsCountEachChannelFromItsOwnOpens(): void
    {
        $campaign = $this->seedCampaign($this->seedForm(['feedback']));
        $emailOnly = $this->seedRecipient($campaign);
        $smsOnly = $this->seedRecipient($campaign);
        $shared = $this->seedSharedCodeRecipient($campaign);
        $unopened = $this->seedRecipient($campaign);
        $this->open((string)$emailOnly->emailInvitationCode);
        $this->open((string)$smsOnly->smsInvitationCode);
        $this->open((string)$shared->smsInvitationCode);
        $this->open((string)$emailOnly->emailInvitationCode);

        $analytics = CampaignManager::$plugin->analytics;
        $overview = $analytics->getOverviewStats((int)$campaign->id, 'all', 'all');
        $funnel = $analytics->getConversionFunnel((int)$campaign->id, 'all', 'all');

        self::assertSame(4, $overview['totalRecipients']);
        self::assertSame(2, $overview['emailsOpened']);
        self::assertSame(2, $overview['smsOpened']);
        self::assertSame(3, $overview['uniqueOpened']);
        self::assertSame(3, $funnel['values'][2]);
        self::assertNull($this->reloadRecipient($unopened)->emailOpenDate);
        self::assertNull($this->reloadRecipient($unopened)->smsOpenDate);
    }

    /**
     * Resolve the recipient the way the invitation page does and record the open.
     */
    private function open(string $code): RecipientRecord
    {
        $recipients = CampaignManager::$plugin->recipients;
        $recipient = $recipients->getRecipientByInvitationCode($code);
        if ($recipient === null) {
            throw new RuntimeException("No recipient resolves invitation code {$code}.");
        }

        $recipients->markAsOpened($recipient);

        return $this->reloadRecipient($recipient);
    }

    /**
     * A recipient created before email and SMS invitations had their own codes.
     */
    private function seedSharedCodeRecipient(?Campaign $campaign = null): RecipientRecord
    {
        $shared = 'shared' . bin2hex(random_bytes(3));

        return $this->seedRecipient($campaign ?? $this->seedCampaign($this->seedForm(['feedback'])), [
            'emailInvitationCode' => $shared,
            'smsInvitationCode' => $shared,
        ]);
    }

    /**
     * Move a recorded open into the past, as if the given time had passed since it.
     */
    private function ageOpenDate(RecipientRecord $recipient, string $column, string $age): void
    {
        $aged = Craft::$app->getDb()->createCommand()
            ->update(
                RecipientRecord::tableName(),
                [$column => Db::prepareDateForDb(new DateTime('-' . $age))],
                ['id' => $recipient->id],
            )
            ->execute();

        if ($aged !== 1) {
            throw new RuntimeException("Test recipient {$recipient->id} could not be aged.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedRow(RecipientRecord $recipient): array
    {
        $row = $this->fetchRow(RecipientRecord::tableName(), ['id' => $recipient->id]);
        if ($row === null) {
            throw new RuntimeException("Test recipient {$recipient->id} no longer exists.");
        }

        return $row;
    }
}
