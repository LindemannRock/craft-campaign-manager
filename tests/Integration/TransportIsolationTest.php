<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\tests\Support\CommandRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Transport fallbacks fail closed before a delivery or worker can execute.
 *
 * @since 5.16.0
 */
final class TransportIsolationTest extends TestCase
{
    public static function forbiddenTransports(): iterable
    {
        yield 'unmocked HTTP' => ['Craft::createGuzzleClient()->get("https://unmocked.example.test/");'];
        yield 'mailer transport event' => ['yii\\base\\Event::trigger(yii\\mail\\BaseMailer::class, yii\\mail\\BaseMailer::EVENT_BEFORE_SEND);'];
        yield 'worker pre-execution event' => ['yii\\base\\Event::trigger(yii\\queue\\Queue::class, yii\\queue\\Queue::EVENT_BEFORE_EXEC);'];
    }

    #[DataProvider('forbiddenTransports')]
    public function testUnmockedDeliveryAndWorkerEventsAreRejected(string $attempt): void
    {
        self::assertStringContainsString('/campaign-manager-fixture-', (string)constant('CRAFT_BASE_PATH'));
        $bootstrap = var_export(dirname(__DIR__) . '/bootstrap.php', true);
        $result = CommandRunner::run([
            PHP_BINARY,
            '-d', 'disable_functions=curl_exec,curl_multi_exec,stream_socket_client,fsockopen,pfsockopen,mail',
            '-d', 'allow_url_fopen=0',
            '-r', "require $bootstrap; $attempt",
        ], dirname(__DIR__, 2), CommandRunner::inheritedEnvironment());
        self::assertSame(90, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Forbidden transport or queue-worker fallback; nothing executed.', $result['stderr']);
        self::assertStringContainsString('intercepted=0; real=0; queue-workers=0', $result['stderr']);
    }

    public function testRawNetworkAndMailFunctionsAreDisabled(): void
    {
        foreach (['curl_exec', 'curl_multi_exec', 'stream_socket_client', 'fsockopen', 'pfsockopen', 'mail'] as $function) {
            self::assertFalse(function_exists($function), $function);
        }
        self::assertSame('0', ini_get('allow_url_fopen'));
    }
}
