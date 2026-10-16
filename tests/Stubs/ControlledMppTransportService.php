<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\smsmanager\services\MppTransportService;

/**
 * Deterministic guard time without sleeping a worker.
 *
 * @since 5.18.0
 */
final class ControlledMppTransportService extends MppTransportService
{
    public int $time = 1792100000;

    protected function now(): int
    {
        return $this->time;
    }
}
