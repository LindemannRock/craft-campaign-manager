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
use craft\db\Command;

/**
 * One-shot scheduling or failure seam immediately before a recipient write.
 * The test owns and restores both the callback and database command class.
 *
 * @since 5.16.0
 */
final class InterceptedRecipientWrite extends Command
{
    /** @var (Closure(): (int|null))|null A result replaces execution; null executes normally. */
    public static ?Closure $beforeWrite = null;

    public function execute(): int
    {
        $sql = $this->getSql();
        if (self::$beforeWrite !== null && str_starts_with($sql, 'UPDATE') && str_contains($sql, 'campaignmanager_recipients')) {
            $callback = self::$beforeWrite;
            self::$beforeWrite = null;
            $result = $callback();
            if ($result !== null) {
                return $result;
            }
        }
        return parent::execute();
    }
}
