<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\helpers;

use Craft;
use craft\helpers\Db;
use DateInterval;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use RuntimeException;
use yii\db\Expression;

/**
 * The shared persistence boundary for successful managed invitations.
 *
 * Send dates distinguish a provisional manual-link deadline from a frozen
 * delivery deadline, including a deliberately unlimited first send. Existing
 * sent rows are never normalized from today's campaign settings.
 *
 * @since 5.16.0
 */
class InvitationDeliveryHelper
{
    /**
     * Read and parse the current campaign setting from the primary database.
     * Call before shortening a link or contacting a transport. Invalid stored
     * durations fail closed; only an explicitly empty setting means no expiry.
     */
    public static function expiryInterval(int $campaignId): ?DateInterval
    {
        $campaign = Craft::$app->getDb()->usePrimary(
            static fn(): ?CampaignRecord => CampaignRecord::findOne($campaignId),
        );
        if ($campaign === null) {
            throw new RuntimeException('The invitation campaign no longer exists.');
        }
        $period = $campaign->invitationExpiryPeriod;
        if (!Campaign::isValidDurationPeriod($period)) {
            throw new RuntimeException('The invitation expiry period cannot be read as a duration.');
        }
        return $period === null || $period === '' ? null : new DateInterval($period);
    }

    /**
     * Record accepted transport exactly once for this channel, establishing
     * expiry only if the database still has neither channel recorded.
     *
     * The CASE reads persisted dates inside the same conditional UPDATE as
     * the send date. Separate channel mutexes can therefore overlap without
     * stale ActiveRecords resetting expiry or overwriting each other's dates.
     * Expiry is assigned before the channel date for databases that evaluate
     * assignments from left to right; both expressions see the old dates.
     *
     * Provider acceptance followed by process death or a failed write remains
     * an ambiguous outcome: a later attempt may deliver again. This method
     * never retries transport, and a write failure is not reported as success.
     */
    public static function recordSuccess(RecipientRecord $recipient, string $channel): void
    {
        if (!in_array($channel, ['sms', 'email'], true)) {
            throw new RuntimeException('Unknown invitation channel.');
        }

        // A campaign can change while transport is in flight. Use its setting
        // at success, not the recipient's cached campaign or queue-time value.
        $interval = self::expiryInterval((int)$recipient->campaignId);
        $sentAt = TimeHelper::now();
        $expiry = $interval === null ? null : Db::prepareDateForDb((clone $sentAt)->add($interval));
        $now = Db::prepareDateForDb($sentAt);
        $column = $channel === 'sms' ? 'smsSendDate' : 'emailSendDate';
        $db = Craft::$app->getDb();
        $written = $db->createCommand()->update(
            RecipientRecord::tableName(),
            [
                'invitationExpiryDate' => new Expression(
                    'CASE WHEN [[smsSendDate]] IS NULL AND [[emailSendDate]] IS NULL THEN :invitationExpiry ELSE [[invitationExpiryDate]] END',
                    [':invitationExpiry' => $expiry],
                ),
                $column => $now,
                'dateUpdated' => $now,
            ],
            ['id' => $recipient->id, $column => null],
        )->execute();

        if ($written !== 1 || !$db->usePrimary(static fn(): bool => $recipient->refresh())) {
            throw new RuntimeException('Accepted invitation delivery could not be durably recorded.');
        }
    }
}
