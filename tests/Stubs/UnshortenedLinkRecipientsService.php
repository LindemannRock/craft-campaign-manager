<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\campaignmanager\services\RecipientsService;

/**
 * Recipients service that keeps the full invitation link instead of asking
 * the URL shortener for a short one, so an invitation can be sent during a
 * test without reaching the network. Everything else behaves as the real
 * service does.
 *
 * @since 5.16.0
 */
final class UnshortenedLinkRecipientsService extends RecipientsService
{
    public function getBitlyUrl(string $surveyUrl): string
    {
        return $surveyUrl;
    }
}
