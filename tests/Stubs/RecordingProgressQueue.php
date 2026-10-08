<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\queue\Queue;

/**
 * Queue that records every progress update a running job reports instead
 * of writing it to the queue table.
 *
 * @since 5.16.0
 */
final class RecordingProgressQueue extends Queue
{
    /**
     * The reported progress percentages, in order.
     *
     * @var list<int>
     */
    public array $progress = [];

    public function setProgress(int $progress, ?string $label = null): void
    {
        $this->progress[] = $progress;
    }
}
