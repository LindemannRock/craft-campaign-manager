<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\helpers\App;
use craft\mutex\Mutex as CraftMutex;
use RuntimeException;
use yii\db\Connection;
use yii\mutex\DbMutex;
use yii\mutex\Mutex;
use yii\mutex\MysqlMutex;

/**
 * A second holder of the application's database locks, on a database
 * connection of its own, the way another queue worker process holds them.
 *
 * It uses the same lock driver and naming as the application mutex, so a
 * name it holds is the name the application contends for. Database locks
 * belong to the connection that took them, which is what makes this a
 * faithful stand-in for another process: the application cannot release
 * these locks, and they end when this connection ends.
 *
 * The owner calls {@see close()} when it is done; that releases whatever is
 * still held and ends the connection.
 *
 * @since 5.16.0
 */
final class SeparateConnectionMutex
{
    /**
     * @var list<string>
     */
    private array $held = [];

    private bool $open = true;

    private function __construct(
        private readonly Connection $connection,
        private readonly DbMutex $mutex,
        private readonly string $namePrefix,
    ) {
    }

    /**
     * Open a holder that contends with the application mutex.
     *
     * @throws RuntimeException when the application mutex is not backed by the database
     */
    public static function contendingWithTheApplication(): self
    {
        $applicationMutex = Craft::$app->getMutex();
        $driver = self::applicationLockDriver();

        if (!$driver instanceof DbMutex) {
            throw new RuntimeException(sprintf(
                'Overlapping deliveries can only be proven against a database-backed mutex; the application uses %s.',
                $driver::class,
            ));
        }

        /** @var Connection $connection */
        $connection = Craft::createObject(App::dbConfig());
        $config = [
            'class' => $driver::class,
            'db' => $connection,
            // Locks are released by their owner, never at process shutdown
            'autoRelease' => false,
        ];
        if ($driver instanceof MysqlMutex) {
            $config['keyPrefix'] = $driver->keyPrefix;
        }

        /** @var DbMutex $mutex */
        $mutex = Craft::createObject($config);

        if ($mutex->db === $driver->db || $mutex->db === Craft::$app->getDb()) {
            throw new RuntimeException('The second lock holder must use a database connection of its own.');
        }

        return new self(
            $connection,
            $mutex,
            $applicationMutex instanceof CraftMutex ? $applicationMutex->namePrefix : '',
        );
    }

    /**
     * The driver that actually takes the application's locks: Craft's mutex
     * component wraps one, any other mutex component is its own driver.
     */
    public static function applicationLockDriver(): Mutex
    {
        $applicationMutex = Craft::$app->getMutex();
        $driver = $applicationMutex instanceof CraftMutex ? $applicationMutex->mutex : $applicationMutex;

        if (!$driver instanceof Mutex) {
            throw new RuntimeException('The application mutex has no resolved lock driver.');
        }

        return $driver;
    }

    /**
     * Take the lock, waiting up to the given number of seconds for it.
     */
    public function acquire(string $name, int $timeout = 0): bool
    {
        $this->requireOpen();

        if (!$this->mutex->acquire($this->namePrefix . $name, $timeout)) {
            return false;
        }

        $this->held[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        $this->requireOpen();

        if (!in_array($name, $this->held, true)) {
            throw new RuntimeException("The lock {$name} is not held by this connection.");
        }
        if (!$this->mutex->release($this->namePrefix . $name)) {
            throw new RuntimeException("The lock {$name} could not be released.");
        }

        $this->held = array_values(array_diff($this->held, [$name]));
    }

    /**
     * End the connection while it still holds its locks, as a worker
     * process that dies mid-send does. Nothing is released explicitly.
     */
    public function dieHoldingItsLocks(): void
    {
        $this->requireOpen();
        $this->connection->close();
        $this->held = [];
        $this->open = false;
    }

    /**
     * Release everything still held and end the connection.
     */
    public function close(): void
    {
        if (!$this->open) {
            return;
        }

        try {
            foreach ($this->held as $name) {
                $this->release($name);
            }
        } finally {
            $this->connection->close();
            $this->held = [];
            $this->open = false;
        }
    }

    private function requireOpen(): void
    {
        if (!$this->open) {
            throw new RuntimeException('This lock holder has already ended its connection.');
        }
    }
}
