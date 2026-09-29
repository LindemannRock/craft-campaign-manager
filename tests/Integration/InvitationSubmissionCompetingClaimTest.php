<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use craft\elements\db\ElementQuery;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\db\CampaignQuery;
use lindemannrock\campaignmanager\tests\TestCase;
use yii\base\Event;

/**
 * Covers two submissions that both pass the invitation checks before either
 * is linked: exactly one of them may become the recipient's response.
 *
 * @since 5.16.0
 */
final class InvitationSubmissionCompetingClaimTest extends TestCase
{
    private ?\Closure $interleaveListener = null;

    protected function cleanupExternalState(): void
    {
        $this->removeInterleaveListener();

        parent::cleanupExternalState();
    }

    public function testOnlyOneOfTwoCompetingSubmissionsIsLinked(): void
    {
        $form = $this->seedForm(['feedback', 'recipientName']);
        $recipient = $this->seedRecipient($this->seedCampaign($form), ['name' => 'Invited Person']);
        $code = (string)$recipient->emailInvitationCode;

        $slower = $this->submitForm($form, null, null, ['feedback' => 'Slower']);
        $faster = $this->submitForm($form, null, null, ['feedback' => 'Faster']);

        // The slower submission has already read the unanswered recipient when
        // it looks up the campaign. Completing the faster one at that moment
        // reproduces two requests that both passed the checks.
        $this->interleaveListener = function() use ($faster, $code): void {
            $this->removeInterleaveListener();
            CampaignManager::$plugin->recipients->processCampaignSubmission($faster, $code);
        };
        Event::on(CampaignQuery::class, ElementQuery::EVENT_AFTER_PREPARE, $this->interleaveListener);

        CampaignManager::$plugin->recipients->processCampaignSubmission($slower, $code);

        self::assertNull($this->interleaveListener, 'The competing submission must have run.');
        self::assertSame((int)$faster->id, (int)$this->reloadRecipient($recipient)->submissionId);
        self::assertSame('Invited Person', $this->persistedSubmissionContent($faster)['recipientName'] ?? null);
        self::assertEmpty($this->persistedSubmissionContent($slower)['recipientName'] ?? null);
    }

    private function removeInterleaveListener(): void
    {
        if ($this->interleaveListener === null) {
            return;
        }

        Event::off(CampaignQuery::class, ElementQuery::EVENT_AFTER_PREPARE, $this->interleaveListener);
        $this->interleaveListener = null;
    }
}
