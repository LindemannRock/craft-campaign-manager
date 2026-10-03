<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Connection;
use lindemannrock\campaignmanager\tests\TestCase;
use RuntimeException;

/**
 * Campaigns saved by early versions stored the numeric SMS Manager sender
 * record ID; current versions store the sender handle in `senderHandle`.
 * An existing MySQL database is brought forward by the SQL below: every
 * numeric value becomes the handle (and provider) of that sender record, an
 * empty value becomes NULL, handles are kept, and the column is renamed.
 * The preflight statements stop the conversion when a value cannot be
 * mapped unambiguously or would not fit the new column.
 *
 * The statements run against connection-scoped temporary tables that shadow
 * the real tables for this connection only; the real tables are never read
 * or written here.
 *
 * @since 5.16.0
 */
final class CampaignSenderHandleConversionTest extends TestCase
{
    /**
     * Numeric sender values that match no sender record; must return no rows.
     */
    public const PREFLIGHT_UNMATCHED_NUMERIC = <<<'SQL'
SELECT c.id, c.senderId
FROM campaignmanager_campaigns c
LEFT JOIN smsmanager_senderids s
  ON c.senderId REGEXP '^[1-9][0-9]*$' AND s.id = CAST(c.senderId AS UNSIGNED)
WHERE c.senderId REGEXP '^[1-9][0-9]*$' AND s.id IS NULL
SQL;

    /**
     * Numeric sender values that are also a real sender handle (ambiguous); must return no rows.
     */
    public const PREFLIGHT_AMBIGUOUS_NUMERIC = <<<'SQL'
SELECT c.id, c.senderId
FROM campaignmanager_campaigns c
JOIN smsmanager_senderids s ON s.handle = c.senderId
WHERE c.senderId REGEXP '^[1-9][0-9]*$'
SQL;

    /**
     * Sender values that would not fit the new column; must return no rows.
     */
    public const PREFLIGHT_OVERLENGTH = <<<'SQL'
SELECT id, senderId
FROM campaignmanager_campaigns
WHERE CHAR_LENGTH(senderId) > 64
SQL;

    /**
     * Non-numeric values that match no sender handle (informational): they are kept as they are.
     */
    public const PREFLIGHT_UNKNOWN_HANDLES = <<<'SQL'
SELECT c.id, c.senderId
FROM campaignmanager_campaigns c
LEFT JOIN smsmanager_senderids s ON s.handle = c.senderId
WHERE c.senderId IS NOT NULL AND c.senderId <> ''
  AND NOT (c.senderId REGEXP '^[1-9][0-9]*$')
  AND s.id IS NULL
SQL;

    public const FORWARD_CONVERT_NUMERIC = <<<'SQL'
UPDATE campaignmanager_campaigns c
JOIN smsmanager_senderids s
  ON c.senderId REGEXP '^[1-9][0-9]*$' AND s.id = CAST(c.senderId AS UNSIGNED)
SET c.senderId = s.handle, c.providerHandle = s.providerHandle
SQL;

    public const FORWARD_EMPTY_TO_NULL = <<<'SQL'
UPDATE campaignmanager_campaigns SET senderId = NULL WHERE senderId = ''
SQL;

    public const FORWARD_RENAME_COLUMN = <<<'SQL'
ALTER TABLE campaignmanager_campaigns
  CHANGE COLUMN senderId senderHandle VARCHAR(64) NULL DEFAULT NULL
SQL;

    public const VERIFY_NO_NUMERIC_VALUES = <<<'SQL'
SELECT COUNT(*) FROM campaignmanager_campaigns WHERE senderHandle REGEXP '^[0-9]+$'
SQL;

    public const VERIFY_NO_EMPTY_VALUES = <<<'SQL'
SELECT COUNT(*) FROM campaignmanager_campaigns WHERE senderHandle = ''
SQL;

    public const VERIFY_ROWS = <<<'SQL'
SELECT c.id, c.senderHandle, c.providerHandle, s.providerHandle AS senderProvider
FROM campaignmanager_campaigns c
LEFT JOIN smsmanager_senderids s ON s.handle = c.senderHandle
ORDER BY c.id
SQL;

    public const ROLLBACK_RENAME_COLUMN = <<<'SQL'
ALTER TABLE campaignmanager_campaigns
  CHANGE COLUMN senderHandle senderId VARCHAR(255) NULL DEFAULT NULL
SQL;

    private const SHADOW_CAMPAIGNS = <<<'SQL'
CREATE TEMPORARY TABLE campaignmanager_campaigns (
  id INT NOT NULL,
  senderId VARCHAR(255) NULL DEFAULT NULL,
  providerHandle VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    private const SHADOW_SENDERS = <<<'SQL'
CREATE TEMPORARY TABLE smsmanager_senderids (
  id INT NOT NULL,
  handle VARCHAR(64) NOT NULL,
  providerHandle VARCHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY (handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    private bool $shadowing = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db()->getIsMysql()) {
            self::markTestSkipped('The conversion is MySQL client SQL.');
        }
    }

    protected function cleanupExternalState(): void
    {
        try {
            parent::cleanupExternalState();
        } finally {
            $this->dropShadowTables();
        }
    }

    public function testNumericSenderValuesBecomeTheSendersHandleAndProvider(): void
    {
        $this->shadowTablesWith(
            senders: [[1, 'dev', 'mpp-sms'], [12, 'haval-kwt', 'mpp-sms'], [1948, 'other', 'twilo']],
            campaigns: [[1731, '1', null], [1823, 'haval-kwt', null], [1900, '', null], [1901, null, null], [1902, '1948', 'mpp-sms']],
        );

        self::assertSame([], $this->rows(self::PREFLIGHT_UNMATCHED_NUMERIC));
        self::assertSame([], $this->rows(self::PREFLIGHT_AMBIGUOUS_NUMERIC));
        self::assertSame([], $this->rows(self::PREFLIGHT_OVERLENGTH));
        self::assertSame([], $this->rows(self::PREFLIGHT_UNKNOWN_HANDLES));

        self::assertSame(2, $this->execute(self::FORWARD_CONVERT_NUMERIC));
        self::assertSame(1, $this->execute(self::FORWARD_EMPTY_TO_NULL));
        self::assertSame(0, $this->execute(self::FORWARD_CONVERT_NUMERIC), 'Converting again changes nothing.');
        $this->execute(self::FORWARD_RENAME_COLUMN);

        self::assertSame('0', (string)$this->db()->createCommand(self::VERIFY_NO_NUMERIC_VALUES)->queryScalar());
        self::assertSame('0', (string)$this->db()->createCommand(self::VERIFY_NO_EMPTY_VALUES)->queryScalar());
        self::assertSame([
            ['id' => 1731, 'senderHandle' => 'dev', 'providerHandle' => 'mpp-sms', 'senderProvider' => 'mpp-sms'],
            ['id' => 1823, 'senderHandle' => 'haval-kwt', 'providerHandle' => null, 'senderProvider' => 'mpp-sms'],
            ['id' => 1900, 'senderHandle' => null, 'providerHandle' => null, 'senderProvider' => null],
            ['id' => 1901, 'senderHandle' => null, 'providerHandle' => null, 'senderProvider' => null],
            ['id' => 1902, 'senderHandle' => 'other', 'providerHandle' => 'twilo', 'senderProvider' => 'twilo'],
        ], $this->rows(self::VERIFY_ROWS));
        self::assertSame(
            [['Field' => 'senderHandle', 'Type' => 'varchar(64)', 'Null' => 'YES', 'Default' => null]],
            $this->senderColumns(),
        );
    }

    public function testTheColumnRenameCanBeRolledBack(): void
    {
        $this->shadowTablesWith(senders: [[1, 'dev', 'mpp-sms']], campaigns: [[1731, 'dev', 'mpp-sms']]);
        $this->execute(self::FORWARD_RENAME_COLUMN);

        $this->execute(self::ROLLBACK_RENAME_COLUMN);

        self::assertSame(
            [['Field' => 'senderId', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Default' => null]],
            $this->senderColumns(),
        );
    }

    public function testThePreflightReportsANumericValueWithNoSenderRecord(): void
    {
        $this->shadowTablesWith(senders: [[1, 'dev', 'mpp-sms']], campaigns: [[1731, '1', null], [1800, '77', null]]);

        self::assertSame([['id' => 1800, 'senderId' => '77']], $this->rows(self::PREFLIGHT_UNMATCHED_NUMERIC));
    }

    public function testThePreflightReportsANumericValueThatIsAlsoAHandle(): void
    {
        $this->shadowTablesWith(senders: [[1, 'dev', 'mpp-sms'], [2, '1', 'mpp-sms']], campaigns: [[1731, '1', null]]);

        self::assertSame([['id' => 1731, 'senderId' => '1']], $this->rows(self::PREFLIGHT_AMBIGUOUS_NUMERIC));
    }

    public function testThePreflightReportsAValueThatWouldNotFitTheNewColumn(): void
    {
        $long = str_repeat('x', 65);
        $this->shadowTablesWith(senders: [[1, 'dev', 'mpp-sms']], campaigns: [[1731, '1', null], [1800, $long, null]]);

        self::assertSame([['id' => 1800, 'senderId' => $long]], $this->rows(self::PREFLIGHT_OVERLENGTH));
    }

    public function testThePreflightListsHandlesThatMatchNoSenderWithoutStoppingOnThem(): void
    {
        $this->shadowTablesWith(senders: [[1, 'dev', 'mpp-sms']], campaigns: [[1731, '1', null], [1800, 'retired', null]]);

        self::assertSame([['id' => 1800, 'senderId' => 'retired']], $this->rows(self::PREFLIGHT_UNKNOWN_HANDLES));
        self::assertSame([], $this->rows(self::PREFLIGHT_UNMATCHED_NUMERIC));
    }

    /**
     * Shadow both tables for this connection with the given rows, refusing to
     * continue unless the shadow is in place.
     *
     * @param list<array{int, string, string}> $senders
     * @param list<array{int, string|null, string|null}> $campaigns
     */
    private function shadowTablesWith(array $senders, array $campaigns): void
    {
        $this->execute(self::SHADOW_SENDERS);
        $this->shadowing = true;
        $this->execute(self::SHADOW_CAMPAIGNS);
        // Craft caches table schemas by name; never let it describe the real tables while they are shadowed
        $this->db()->getSchema()->refresh();

        foreach (['smsmanager_senderids', 'campaignmanager_campaigns'] as $table) {
            $create = $this->db()->createCommand('SHOW CREATE TABLE ' . $table)->queryOne();
            if (!is_array($create) || !str_starts_with((string)($create['Create Table'] ?? ''), 'CREATE TEMPORARY TABLE')) {
                throw new RuntimeException("$table is not shadowed by a temporary table; refusing to continue.");
            }
        }

        $this->db()->createCommand()->batchInsert('smsmanager_senderids', ['id', 'handle', 'providerHandle'], $senders)->execute();
        $this->db()->createCommand()->batchInsert('campaignmanager_campaigns', ['id', 'senderId', 'providerHandle'], $campaigns)->execute();
    }

    private function dropShadowTables(): void
    {
        if (!$this->shadowing) {
            return;
        }

        $this->shadowing = false;
        $this->db()->createCommand('DROP TEMPORARY TABLE IF EXISTS campaignmanager_campaigns, smsmanager_senderids')->execute();
        $this->db()->getSchema()->refresh();

        foreach (['smsmanager_senderids', 'campaignmanager_campaigns'] as $table) {
            try {
                $create = $this->db()->createCommand('SHOW CREATE TABLE ' . $table)->queryOne();
            } catch (\yii\db\Exception) {
                // No table of that unprefixed name exists on this install; nothing is shadowed
                continue;
            }
            if (is_array($create) && str_starts_with((string)($create['Create Table'] ?? ''), 'CREATE TEMPORARY TABLE')) {
                throw new RuntimeException("$table is still shadowed after cleanup.");
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return array_values($this->db()->createCommand($sql)->queryAll());
    }

    /**
     * @return list<array{Field: string, Type: string, Null: string, Default: string|null}>
     */
    private function senderColumns(): array
    {
        $columns = [];
        foreach ($this->db()->createCommand("SHOW COLUMNS FROM campaignmanager_campaigns LIKE 'sender%'")->queryAll() as $column) {
            $columns[] = [
                'Field' => (string)$column['Field'],
                'Type' => (string)$column['Type'],
                'Null' => (string)$column['Null'],
                'Default' => $column['Default'],
            ];
        }

        return $columns;
    }

    private function execute(string $sql): int
    {
        return $this->db()->createCommand($sql)->execute();
    }

    private function db(): Connection
    {
        return Craft::$app->getDb();
    }
}
