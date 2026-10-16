<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\queue\Queue;

/**
 * Deny external delivery and worker execution before application bootstrap.
 *
 * @since 5.16.0
 */
final class TransportIsolation
{
    public static int $interceptedRequests = 0;
    public static ?MockHandler $responses = null;
    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        Craft::$container->set(Client::class, static function($container, array $params): Client {
            $config = $params[0] ?? [];
            $mock = self::$responses ?? $config['handler'] ?? null;
            $config['handler'] = static function($request, array $options) use ($mock) {
                if ($mock instanceof MockHandler) {
                    self::$interceptedRequests++;
                    return $mock($request, $options);
                }
                // Fresh Craft installation checks its license; answer locally.
                if ($request->getUri()->getHost() === 'api.craftcms.com') {
                    return Create::promiseFor(new Response(200, [], '{}'));
                }
                self::deny();
            };
            return new Client($config);
        });
        Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, self::deny(...));
        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, self::deny(...));
        register_shutdown_function(static function(): void {
            fwrite(STDERR, 'Campaign transport isolation: intercepted=' . self::$interceptedRequests . "; real=0; queue-workers=0\n");
        });
    }

    public static function deny(): never
    {
        fwrite(STDERR, "Forbidden transport or queue-worker fallback; nothing executed.\n");
        exit(90);
    }
}
