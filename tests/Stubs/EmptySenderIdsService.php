<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\services\SenderIdsService;

/**
 * SMS Manager sender service of a project that has no sender at all, so
 * nothing can resolve as a default.
 *
 * @since 5.16.0
 */
final class EmptySenderIdsService extends SenderIdsService
{
    public function getAllSenderIds(bool $enabledOnly = false): array
    {
        return [];
    }

    public function getDefaultSenderId(int|string|null $providerIdOrHandle = null): ?SenderIdRecord
    {
        return null;
    }

    public function getSenderIdByHandle(string $handle): ?SenderIdRecord
    {
        return null;
    }
}
