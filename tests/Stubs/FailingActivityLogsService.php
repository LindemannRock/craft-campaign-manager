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
use RuntimeException;

/**
 * An activity log service whose every write fails, to show that an
 * operation's own outcome does not depend on its log being written.
 *
 * @since 5.16.0
 */
final class FailingActivityLogsService extends ActivityLogsService
{
    /**
     * @var list<string> The actions whose logging was attempted
     */
    public array $attemptedActions = [];

    public function log(string $action, array $context = []): void
    {
        $this->attemptedActions[] = $action;

        throw new RuntimeException('The activity log could not be written.');
    }
}
