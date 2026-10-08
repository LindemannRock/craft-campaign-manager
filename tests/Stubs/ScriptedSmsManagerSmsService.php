<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use Closure;
use lindemannrock\smsmanager\services\SmsService;
use Throwable;

/**
 * SMS Manager send service that records every message handed to it instead
 * of dispatching it to a provider, and then answers the way the test
 * scripted: it can run other work while the message is "at the provider",
 * report a failed send, or throw.
 *
 * Campaign Manager's own services run unchanged around it, so a successful
 * answer is followed by the real send-date write.
 *
 * @since 5.16.0
 */
final class ScriptedSmsManagerSmsService extends SmsService
{
    /**
     * @var list<array{to: string, senderIdHandle: string, siteId: int|null}>
     */
    public array $sentMessages = [];

    /**
     * Runs while a message is at the provider, before the provider answers.
     * Receives the number of the message, counted from 1.
     *
     * @var (Closure(int): void)|null
     */
    public ?Closure $duringSend = null;

    /**
     * The provider's answers to successive messages; a message past the end
     * of the list is accepted.
     *
     * @var list<bool|Throwable>
     */
    public array $answers = [];

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
            'senderIdHandle' => $senderIdHandle,
            'siteId' => $siteId,
        ];
        $answer = array_shift($this->answers) ?? true;

        if ($this->duringSend !== null) {
            ($this->duringSend)(count($this->sentMessages));
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * The phone numbers messages were handed over for, in order.
     *
     * @return list<string>
     */
    public function sentTo(): array
    {
        return array_column($this->sentMessages, 'to');
    }
}
