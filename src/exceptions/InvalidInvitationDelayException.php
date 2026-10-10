<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\exceptions;

use RuntimeException;

/**
 * Thrown when a campaign's stored invitation delay cannot be read as a
 * duration. No invitation of that campaign may be queued or sent until an
 * administrator corrects the campaign; the stored value is never changed
 * or reinterpreted by the plugin.
 *
 * @since 5.16.0
 */
class InvalidInvitationDelayException extends RuntimeException
{
}
