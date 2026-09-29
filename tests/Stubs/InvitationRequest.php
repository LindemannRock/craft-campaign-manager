<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\console\Request as CraftConsoleRequest;

/**
 * Console-mode request stub that carries the query string of an invitation
 * URL, so a form submission can be processed as if it was posted from
 * `…/invitation?code=…`.
 *
 * Extends Craft's console request so `getIsConsoleRequest()` stays truthful
 * under the console-bootstrapped test harness.
 *
 * @since 5.16.0
 */
final class InvitationRequest extends CraftConsoleRequest
{
    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $queryParams = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Mirror `yii\web\Request::get()`.
     */
    public function get(?string $name = null, mixed $defaultValue = null): mixed
    {
        if ($name === null) {
            return $this->queryParams;
        }

        return $this->queryParams[$name] ?? $defaultValue;
    }

    /**
     * Mirror `yii\web\Request::getQueryParam()`.
     */
    public function getQueryParam(string $name, mixed $defaultValue = null): mixed
    {
        return $this->queryParams[$name] ?? $defaultValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }
}
