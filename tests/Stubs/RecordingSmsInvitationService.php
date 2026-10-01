<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\RecipientsService;

/**
 * Recipients service that records which recipients an SMS invitation would
 * be sent to instead of sending it. Everything else behaves as the real
 * service does.
 *
 * @since 5.16.0
 */
final class RecordingSmsInvitationService extends RecipientsService
{
    /**
     * @var list<int>
     */
    public array $sentToRecipientIds = [];

    public function processSmsInvitation(
        CampaignRecord $campaign,
        RecipientRecord $recipient,
        ?string $providerHandle = null,
        ?string $senderIdHandle = null,
    ): bool {
        $this->sentToRecipientIds[] = (int)$recipient->id;

        return true;
    }
}
