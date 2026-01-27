<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\helpers;

use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use Exception;

/**
 * Time Helper
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class TimeHelper
{
    /**
     * Get current DateTime
     */
    public static function now(): DateTime
    {
        return DateTimeHelper::now();
    }

    /**
     * Get a DateTime from now plus interval
     */
    public static function fromNow(DateInterval|string|int $interval): DateTime
    {
        $interval = static::normalizeInterval($interval);
        return static::now()->add($interval);
    }

    /**
     * Normalizes a time duration value into a DateInterval
     *
     * Accepted formats:
     * - non-zero integer (the duration in seconds)
     * - a string in duration interval format (ISO 8601)
     * - a string in relative date/time format
     * - DateInterval object
     *
     * @throws Exception if the interval cannot be parsed
     */
    public static function normalizeInterval(DateInterval|string|int $interval): DateInterval
    {
        if ($interval instanceof DateInterval) {
            return $interval;
        }

        if (is_numeric($interval)) {
            $interval = 'PT' . intval($interval) . 'S';
        }

        // At this point $interval is guaranteed to be a string
        if (StringHelper::startsWith($interval, 'P')) {
            return new DateInterval($interval);
        }

        return DateInterval::createFromDateString($interval);
    }

    /**
     * Check if an interval string is valid
     */
    public static function isValidInterval(DateInterval|string|int $interval): bool
    {
        try {
            self::normalizeInterval($interval);
            return true;
        } catch (Exception) {
            return false;
        }
    }
}
