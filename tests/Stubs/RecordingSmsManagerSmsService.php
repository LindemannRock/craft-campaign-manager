<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\smsmanager\services\SmsService;

/**
 * SMS Manager send service that records every message handed to it instead
 * of dispatching it to a provider. What it records is exactly what Campaign
 * Manager asked SMS Manager to send.
 *
 * @since 5.16.0
 */
final class RecordingSmsManagerSmsService extends SmsService
{
    /**
     * @var list<array{to: string, message: string, senderIdHandle: string, language: string, sourcePlugin: string|null, siteId: int|null}>
     */
    public array $sentMessages = [];

    public function sendWithHandle(
        string $to,
        string $message,
        string $senderIdHandle,
        string $language = 'en',
        ?string $sourcePlugin = null,
        ?int $sourceElementId = null,
        ?int $siteId = null,
    ): bool {
        $this->sentMessages[] = [
            'to' => $to,
            'message' => $message,
            'senderIdHandle' => $senderIdHandle,
            'language' => $language,
            'sourcePlugin' => $sourcePlugin,
            'siteId' => $siteId,
        ];

        return true;
    }
}
