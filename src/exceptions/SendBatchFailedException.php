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
 * Thrown when a SendBatchJob completes with zero successful sends and at least
 * one error. Signals to the queue UI that the batch failed and to `canRetry()`
 * that retrying won't help (the cause is a template/config/provider issue, not
 * a transient one).
 *
 * @since 5.11.0
 */
class SendBatchFailedException extends RuntimeException
{
}
