<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\db\CampaignQuery;
use lindemannrock\campaignmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use verbb\formie\elements\Submission;
use yii\base\Event;

/**
 * Manual links and Formie association share the stored invitation deadline,
 * including a request that crosses that deadline while claiming a response.
 *
 * @since 5.16.0
 */
final class InvitationValidityBoundaryTest extends TestCase
{
    private int $clockPauses = 0;
    private ?\Closure $interleaveListener = null;

    protected function cleanupExternalState(): void
    {
        $this->removeListener();
        while ($this->clockPauses > 0) {
            DateTimeHelper::resume();
            $this->clockPauses--;
        }
        parent::cleanupExternalState();
    }

    /** @return array<string, array{string, bool}> */
    public static function deadlines(): array
    {
        return [
            'before' => ['2026-03-15 08:59:59', false],
            'exactly at' => ['2026-03-15 09:00:00', true],
            'after' => ['2026-03-15 09:00:01', true],
        ];
    }

    #[DataProvider('deadlines')]
    public function testManualLinkAndFormieAssociationEnforceTheSameDeadline(string $now, bool $expired): void
    {
        $this->at('2026-03-01 09:00:00');
        $form = $this->seedForm(['feedback']);
        $recipient = $this->seedRecipient($this->seedCampaign($form, ['invitationExpiryPeriod' => 'P14D']));
        $this->at($now);
        self::assertSame($expired, $recipient->invitationIsExpired());
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode);
        $stored = $this->reloadRecipient($recipient);
        self::assertSame($expired ? null : (int)$submission->id, $stored->submissionId === null ? null : (int)$stored->submissionId);
        self::assertNotNull(Submission::find()->id($submission->id)->status(null)->one(), 'Formie retains its independently accepted submission.');
        self::assertNull($stored->smsSendDate);
        self::assertNull($stored->emailSendDate);
        self::assertSame('2026-03-15 09:00:00', $stored->invitationExpiryDate?->format('Y-m-d H:i:s'));
    }

    public function testARequestCrossingExpiryCannotWinTheConditionalClaim(): void
    {
        $this->at('2026-03-01 09:00:00');
        $form = $this->seedForm(['feedback', 'recipientName']);
        $recipient = $this->seedRecipient($this->seedCampaign($form, ['invitationExpiryPeriod' => 'P14D']));
        $submission = $this->submitForm($form, null);
        $this->at('2026-03-15 08:59:59');
        $this->interleaveListener = function(): void {
            $this->removeListener();
            $this->at('2026-03-15 09:00:00');
        };
        Event::on(CampaignQuery::class, ElementQuery::EVENT_AFTER_PREPARE, $this->interleaveListener);
        CampaignManager::$plugin->recipients->processCampaignSubmission($submission, (string)$recipient->emailInvitationCode);
        self::assertNull($this->interleaveListener, 'The request must cross expiry after its preliminary check.');
        self::assertNull($this->reloadRecipient($recipient)->submissionId);
        self::assertEmpty($this->persistedSubmissionContent($submission)['recipientName'] ?? null);
        self::assertNotNull(Submission::find()->id($submission->id)->status(null)->one());
    }

    private function at(string $time): void
    {
        DateTimeHelper::pause(new DateTime($time));
        $this->clockPauses++;
    }

    private function removeListener(): void
    {
        if ($this->interleaveListener !== null) {
            Event::off(CampaignQuery::class, ElementQuery::EVENT_AFTER_PREPARE, $this->interleaveListener);
            $this->interleaveListener = null;
        }
    }
}
