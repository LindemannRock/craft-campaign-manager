<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use RuntimeException;

/**
 * Activity log service whose site rows cannot be written, as when the
 * database refuses them, so that a test can show the log is not kept either.
 *
 * @since 5.16.0
 */
final class SiteRowsFailingActivityLogsService extends NonTrimmingActivityLogsService
{
    protected function insertSiteRows(int $logId, array $siteIds): void
    {
        throw new RuntimeException('The site rows could not be written.');
    }
}
