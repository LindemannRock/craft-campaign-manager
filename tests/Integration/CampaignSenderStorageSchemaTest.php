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
use lindemannrock\campaignmanager\migrations\Install;
use lindemannrock\campaignmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * A campaign stores the handle of the SMS Manager sender it sends through in
 * `senderHandle`, sized like SMS Manager's own handle column, and nothing
 * else: there is no numeric sender ID column.
 *
 * @since 5.16.0
 */
#[CoversClass(Install::class)]
final class CampaignSenderStorageSchemaTest extends TestCase
{
    public function testTheInstalledCampaignsTableStoresTheSenderHandle(): void
    {
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%campaignmanager_campaigns}}', true);
        if ($schema === null) {
            throw new RuntimeException('The campaigns table is not installed.');
        }

        $column = $schema->getColumn('senderHandle');
        self::assertNotNull($column, 'Campaigns store their sender in senderHandle.');
        self::assertSame('string', $column->type);
        self::assertSame(64, $column->size);
        self::assertTrue($column->allowNull, 'No sender means the campaign inherits the default.');
        self::assertNull($schema->getColumn('senderId'), 'There is no numeric sender ID column.');
    }

    public function testAFreshInstallCreatesOnlyTheSenderHandleColumn(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

        self::assertStringContainsString("'senderHandle' => \$this->string(64),", $source);
        self::assertStringNotContainsString("'senderId'", $source);
    }
}
