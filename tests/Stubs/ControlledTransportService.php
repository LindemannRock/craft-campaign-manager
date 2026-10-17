<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\smsmanager\services\TransportService;

/**
 * Deterministic guard time without sleeping a worker.
 *
 * @since 5.18.1
 */
final class ControlledTransportService extends TransportService
{
    public int $time = 1792100000;

    /** @var (\Closure(): void)|null */
    public ?\Closure $afterStatus = null;

    public function status(\lindemannrock\smsmanager\models\TransportContext $context): array
    {
        $status = parent::status($context);
        if ($this->afterStatus !== null) {
            $callback = $this->afterStatus;
            $this->afterStatus = null;
            $callback();
        }
        return $status;
    }

    protected function now(): int
    {
        return $this->time;
    }
}
