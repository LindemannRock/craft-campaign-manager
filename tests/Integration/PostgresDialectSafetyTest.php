<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\base\testing\SqlDialectLinter;
use lindemannrock\campaignmanager\tests\TestCase;

/**
 * Pins PostgreSQL dialect safety for hand-written SQL.
 *
 * CI runs on MySQL, which is case-insensitive for identifiers, allows
 * MAX()/MIN() over its tinyint booleans, and resolves upsert column
 * references without ambiguity — so none of these bugs surface there.
 * PostgreSQL folds unquoted identifiers to lowercase, has no boolean
 * max/min, and rejects bare column references inside ON CONFLICT DO UPDATE.
 */
final class PostgresDialectSafetyTest extends TestCase
{
    /**
     * Boolean columns of this plugin's tables (from Install.php) — MAX()/MIN()
     * directly over one is a PostgreSQL type error; the linter needs the names
     * since types aren't visible in source.
     */
    private const BOOLEAN_COLUMNS = [
        'enableActivityLogs', 'activityAutoTrimLogs', 'showSeconds',
        'exportsCsv', 'exportsJson', 'exportsExcel',
    ];

    public function testRawSqlLiteralsAreDialectSafe(): void
    {
        $violations = SqlDialectLinter::scanDirectory(
            dirname(__DIR__, 2) . '/src',
            [],
            self::BOOLEAN_COLUMNS,
        );

        self::assertSame([], $violations, "PostgreSQL-unsafe raw SQL found:\n" . implode("\n", $violations));
    }
}
