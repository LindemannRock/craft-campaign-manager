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
use lindemannrock\campaignmanager\services\EmailsService;

/**
 * Emails service that records which recipients an invitation email would be
 * sent to instead of sending it.
 *
 * @since 5.16.0
 */
final class RecordingEmailsService extends EmailsService
{
    /**
     * @var list<int>
     */
    public array $sentToRecipientIds = [];

    public function sendNotificationEmail(RecipientRecord $recipient, CampaignRecord $campaign): bool
    {
        $this->sentToRecipientIds[] = (int)$recipient->id;

        return true;
    }
}
