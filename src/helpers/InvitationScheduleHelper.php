<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\helpers;

use craft\helpers\DateTimeHelper;
use DateInterval;
use DateTime;
use Exception;
use lindemannrock\campaignmanager\exceptions\InvalidInvitationDelayException;

/**
 * When a recipient's invitations may be sent.
 *
 * A campaign's invitation delay counts from the moment a recipient was
 * added: invitations are not sent before that moment plus the delay. The
 * delay is read from the campaign whenever work is queued and again when a
 * worker is about to send, so a delay changed in between is honoured. A
 * stored delay that cannot be read is never treated as no delay: every
 * method here refuses it, so nothing is queued or sent until the campaign
 * is corrected.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.16.0
 */
class InvitationScheduleHelper
{
    /**
     * The longest wait a queued job may carry; the queue stores it in a
     * signed 32-bit column. A recipient still not eligible when the job runs
     * is scheduled again from there.
     */
    public const MAX_QUEUE_DELAY_SECONDS = 2147483647;

    /**
     * The campaign's invitation delay as a duration, or null when it has
     * none.
     *
     * @param string|null $delayPeriod The campaign's invitation delay, an ISO 8601 duration or empty
     * @throws InvalidInvitationDelayException when the delay cannot be read as a duration
     */
    public static function delayInterval(?string $delayPeriod): ?DateInterval
    {
        if ($delayPeriod === null || $delayPeriod === '') {
            return null;
        }

        try {
            return new DateInterval($delayPeriod);
        } catch (Exception $e) {
            throw new InvalidInvitationDelayException(sprintf(
                'The invitation delay period "%s" cannot be read as a duration: %s',
                $delayPeriod,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * The earliest moment a recipient added at the given time may be sent
     * an invitation. Without a delay that is the moment it was added.
     *
     * @param DateTime|string|null $createdAt The recipient's creation time, as stored or as a DateTime; now when unknown
     * @param string|null $delayPeriod The campaign's invitation delay, an ISO 8601 duration or empty
     * @throws InvalidInvitationDelayException when the delay cannot be read as a duration
     */
    public static function eligibleAt(DateTime|string|null $createdAt, ?string $delayPeriod): DateTime
    {
        $delay = self::delayInterval($delayPeriod);

        $created = $createdAt instanceof DateTime ? clone $createdAt : DateTimeHelper::toDateTime((string)$createdAt);
        if (!$created instanceof DateTime) {
            $created = DateTimeHelper::now();
        }

        return $delay === null ? $created : $created->add($delay);
    }

    /**
     * How many seconds a recipient added at the given time still has to
     * wait, capped at {@see self::MAX_QUEUE_DELAY_SECONDS}; 0 when it may be
     * sent to now.
     *
     * @param DateTime|string|null $createdAt The recipient's creation time, as stored or as a DateTime; now when unknown
     * @param string|null $delayPeriod The campaign's invitation delay, an ISO 8601 duration or empty
     * @param DateTime|null $now The moment to count from; the current time by default
     * @throws InvalidInvitationDelayException when the delay cannot be read as a duration
     */
    public static function secondsUntilEligible(DateTime|string|null $createdAt, ?string $delayPeriod, ?DateTime $now = null): int
    {
        $now ??= DateTimeHelper::now();
        $remaining = self::eligibleAt($createdAt, $delayPeriod)->getTimestamp() - $now->getTimestamp();

        return (int)min(self::MAX_QUEUE_DELAY_SECONDS, max(0, $remaining));
    }
}
