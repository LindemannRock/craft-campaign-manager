<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\campaignmanager\services\ActivityLogsService;

/**
 * Activity log service that writes logs but never trims existing ones, so a
 * test run cannot remove logs it did not create.
 *
 * @since 5.16.0
 */
class NonTrimmingActivityLogsService extends ActivityLogsService
{
    public function trimLogs(): void
    {
    }
}
