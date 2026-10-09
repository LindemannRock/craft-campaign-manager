<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\ScriptedCodeRecipientsService;
use lindemannrock\campaignmanager\tests\TestCase;

/**
 * Covers the invitation codes a recipient is given when it is created, that
 * they are kept on later saves, and that every stored code still resolves
 * its recipient.
 *
 * @since 5.16.0
 */
final class RecipientInvitationCodeGenerationTest extends TestCase
{
    public function testNewRecipientReceivesDistinctEmailAndSmsCodes(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));

        $emailCode = (string)$recipient->emailInvitationCode;
        $smsCode = (string)$recipient->smsInvitationCode;

        self::assertSame(12, strlen($emailCode));
        self::assertSame(12, strlen($smsCode));
        self::assertNotSame(
            $emailCode,
            $smsCode,
            'Email and SMS invitations need their own codes so an open can be attributed to its channel.',
        );
    }

    public function testSmsCodeIsRegeneratedWhenItRepeatsTheEmailCode(): void
    {
        $repeated = '__cmt' . bin2hex(random_bytes(3));
        $distinct = '__cmt' . bin2hex(random_bytes(3));
        $codes = new ScriptedCodeRecipientsService([$repeated, $repeated, $distinct]);
        $this->swapPluginComponent('campaign-manager', 'recipients', $codes);

        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));

        self::assertSame($repeated, $recipient->emailInvitationCode);
        self::assertSame($distinct, $recipient->smsInvitationCode);
        self::assertSame([], $codes->remainingCodes(), 'The repeated code must be discarded and the next one used.');
    }

    public function testExistingCodesSurviveAnOrdinarySave(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $emailCode = $recipient->emailInvitationCode;
        $smsCode = $recipient->smsInvitationCode;

        $edited = $this->reloadRecipient($recipient);
        $edited->name = $this->nextTestMarker('Campaign Manager test ', 'renamed');
        self::assertTrue($edited->save(false));

        $saved = $this->reloadRecipient($recipient);
        self::assertSame($emailCode, $saved->emailInvitationCode);
        self::assertSame($smsCode, $saved->smsInvitationCode);
    }

    public function testSharedCodeSurvivesAnOrdinarySave(): void
    {
        $shared = 'shared' . bin2hex(random_bytes(3));
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])), [
            'emailInvitationCode' => $shared,
            'smsInvitationCode' => $shared,
        ]);

        $edited = $this->reloadRecipient($recipient);
        $edited->name = $this->nextTestMarker('Campaign Manager test ', 'renamed');
        self::assertTrue($edited->save(false));

        $saved = $this->reloadRecipient($recipient);
        self::assertSame($shared, $saved->emailInvitationCode, 'A shared code is an existing link and must never be replaced.');
        self::assertSame($shared, $saved->smsInvitationCode);
    }

    public function testEachChannelCodeResolvesTheRecipient(): void
    {
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $recipients = CampaignManager::$plugin->recipients;

        self::assertSame((int)$recipient->id, (int)$recipients->getRecipientByInvitationCode((string)$recipient->emailInvitationCode)?->id);
        self::assertSame((int)$recipient->id, (int)$recipients->getRecipientByInvitationCode((string)$recipient->smsInvitationCode)?->id);
    }

    public function testRecipientWithOneSharedCodeStillResolvesByThatCode(): void
    {
        $shared = 'shared' . bin2hex(random_bytes(3));
        $recipient = $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])), [
            'emailInvitationCode' => $shared,
            'smsInvitationCode' => $shared,
        ]);

        $resolved = CampaignManager::$plugin->recipients->getRecipientByInvitationCode($shared);

        self::assertInstanceOf(RecipientRecord::class, $resolved);
        self::assertSame((int)$recipient->id, (int)$resolved->id);
        self::assertSame($shared, $resolved->emailInvitationCode);
        self::assertSame($shared, $resolved->smsInvitationCode);
    }

    public function testMissingAndUnknownCodesResolveNoRecipient(): void
    {
        $this->seedRecipient($this->seedCampaign($this->seedForm(['feedback'])));
        $recipients = CampaignManager::$plugin->recipients;

        self::assertNull($recipients->getRecipientByInvitationCode(''));
        self::assertNull($recipients->getRecipientByInvitationCode('__cmt_unknown_' . bin2hex(random_bytes(3))));
    }
}
