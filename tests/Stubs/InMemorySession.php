<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\web\Session;

/**
 * Session that keeps its values in memory, so controller actions that read
 * and write the user session can run in the console-bootstrapped test
 * runtime without starting a PHP session.
 *
 * Flash messages are stored under their plain keys and read back through
 * {@see getNotice()} and {@see getError()}.
 *
 * @since 5.16.0
 */
final class InMemorySession extends Session
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function open(): void
    {
    }

    public function close(): void
    {
    }

    public function has($key): bool
    {
        return array_key_exists((string)$key, $this->values);
    }

    public function get($key, $defaultValue = null): mixed
    {
        return $this->values[(string)$key] ?? $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->values[(string)$key] = $value;
    }

    public function remove($key): mixed
    {
        $key = (string)$key;
        $value = $this->values[$key] ?? null;
        unset($this->values[$key]);

        return $value;
    }

    public function removeAll(): void
    {
        $this->values = [];
    }

    public function setNotice(string $message, array $settings = []): void
    {
        $this->set('notice', $message);
    }

    public function setError(string $message, array $settings = []): void
    {
        $this->set('error', $message);
    }

    public function getNotice(): ?string
    {
        $notice = $this->get('notice');

        return is_string($notice) ? $notice : null;
    }

    public function getError(): ?string
    {
        $error = $this->get('error');

        return is_string($error) ? $error : null;
    }
}
