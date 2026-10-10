<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use PDO;

/**
 * Owns one disposable Craft project (Formie, Logging Library, SMS Manager, the rating field, and Campaign Manager installed) plus its database for standalone tests.
 *
 * @internal
 * @since 5.16.0
 */
final class DisposableCraftProject
{
    public const SOURCE_VENDOR_ENV = 'CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';

    private const DATABASE_PREFIX = 'cm_qg_';

    private string $runId;
    private string $projectRoot;
    private string $databaseName;
    private string $vendorRoot;
    private string $securityKey;
    private bool $databaseCreated = false;
    private bool $projectCreated = false;
    private bool $cleanupComplete = false;
    private bool $cleanupGuardsInstalled = false;

    /** @var resource|null */
    private $activeProcess = null;

    /** @var list<array{command: list<string>, exitCode: int, stdout: string, stderr: string}> */
    private array $commands = [];

    public function __construct(
        private readonly string $packageRoot,
        string $vendorRoot,
    ) {
        $resolvedVendor = realpath($vendorRoot);
        if ($resolvedVendor === false || !is_file($resolvedVendor . '/autoload.php')) {
            throw new \InvalidArgumentException('The disposable fixture vendor root is invalid.');
        }

        $this->runId = bin2hex(random_bytes(8));
        $this->projectRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'campaign-manager-fixture-' . $this->runId;
        $this->databaseName = self::DATABASE_PREFIX . $this->runId;
        $this->vendorRoot = rtrim($resolvedVendor, DIRECTORY_SEPARATOR);
        $this->securityKey = bin2hex(random_bytes(32));
    }

    /** @return array{fixture: array<string, string>, phpunit: array{command: list<string>, exitCode: int, stdout: string, stderr: string}, cleanup: array{projectRemoved: bool, databaseRemoved: bool}} */
    public function run(array $phpunitArguments = []): array
    {
        $this->installCleanupGuards();
        $failure = null;
        $phpunit = null;

        try {
            $this->createDatabase();
            $this->createProject();
            $this->installCraft();
            $this->createAdditionalSites();
            $this->installPlugins();
            $phpunit = $this->runPhpunit($phpunitArguments);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $cleanup = $this->cleanupWithFailure($failure);

        if ($failure !== null) {
            throw new \RuntimeException(
                $failure->getMessage() . "\nDisposable command evidence:\n"
                . json_encode($this->commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                previous: $failure,
            );
        }
        if (!is_array($phpunit)) {
            throw new \LogicException('Disposable PHPUnit result was not recorded.');
        }

        return [
            'fixture' => [
                'runId' => $this->runId,
                'projectRoot' => $this->projectRoot,
                'databaseName' => $this->databaseName,
            ],
            'phpunit' => $phpunit,
            'cleanup' => $cleanup,
        ];
    }

    /** @return array{failure: string, cleanup: array{projectRemoved: bool, databaseRemoved: bool}} */
    public function runFailureProbe(): array
    {
        $this->installCleanupGuards();
        $failure = null;

        try {
            $this->createDatabase();
            $this->createProject();
            throw new \RuntimeException('Synthetic disposable runner failure.');
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        return [
            'failure' => $failure->getMessage(),
            'cleanup' => $this->cleanupWithFailure(null),
        ];
    }

    public function waitForInterruption(string $readyPath): never
    {
        $this->installCleanupGuards();
        $this->createDatabase();
        $this->createProject();
        $this->publishLifecycleReadiness($readyPath);

        for (;;) {
            usleep(100000);
        }
    }

    public function exitThroughShutdown(string $readyPath): never
    {
        $this->installCleanupGuards();
        $this->createDatabase();
        $this->createProject();
        $this->publishLifecycleReadiness($readyPath);

        exit(74);
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool} */
    public function cleanup(): array
    {
        if ($this->cleanupComplete) {
            return [
                'projectRemoved' => true,
                'databaseRemoved' => true,
            ];
        }

        $errors = [];

        if (is_resource($this->activeProcess)) {
            @proc_terminate($this->activeProcess, 15);
            @proc_close($this->activeProcess);
            $this->activeProcess = null;
        }

        if ($this->databaseCreated) {
            try {
                $this->adminPdo()->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'database: ' . $exception->getMessage();
            }
        }

        if ($this->projectCreated || file_exists($this->projectRoot)) {
            try {
                $this->removeOwnedProjectRoot();
                $this->projectCreated = false;
            } catch (\Throwable $exception) {
                $errors[] = 'filesystem: ' . $exception->getMessage();
            }
        }

        try {
            if ($this->databaseExists()) {
                $errors[] = "exact run-owned database {$this->databaseName} remains";
            }
        } catch (\Throwable $exception) {
            $errors[] = 'database verification: ' . $exception->getMessage();
        }

        if ($errors !== []) {
            throw new \RuntimeException('Disposable cleanup failed: ' . implode('; ', $errors));
        }

        $this->cleanupComplete = true;

        return [
            'projectRemoved' => !file_exists($this->projectRoot),
            'databaseRemoved' => !$this->databaseExists(),
        ];
    }

    /** @return array{projectRemoved: bool, databaseRemoved: bool} */
    private function cleanupWithFailure(?\Throwable $original): array
    {
        try {
            return $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($original !== null) {
                throw new \RuntimeException(
                    'Disposable fixture failed and cleanup also failed: '
                    . $original->getMessage() . '; cleanup: ' . $cleanupFailure->getMessage(),
                    previous: $cleanupFailure,
                );
            }

            throw $cleanupFailure;
        }
    }

    private function installCleanupGuards(): void
    {
        if ($this->cleanupGuardsInstalled) {
            return;
        }
        $this->cleanupGuardsInstalled = true;

        register_shutdown_function(function(): void {
            try {
                $this->cleanup();
            } catch (\Throwable $exception) {
                fwrite(STDERR, 'Disposable shutdown cleanup failed: ' . $exception->getMessage() . PHP_EOL);
                exit(1);
            }
        });

        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGINT => 130, SIGTERM => 143, SIGHUP => 129] as $signal => $status) {
            pcntl_signal($signal, function() use ($status): never {
                try {
                    $this->cleanup();
                } catch (\Throwable $exception) {
                    fwrite(STDERR, 'Disposable signal cleanup failed: ' . $exception->getMessage() . PHP_EOL);
                    exit(1);
                }

                exit($status);
            });
        }
    }

    private function publishLifecycleReadiness(string $readyPath): void
    {
        $payload = json_encode([
            'databaseName' => $this->databaseName,
            'projectRoot' => $this->projectRoot,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($readyPath, $payload) === false) {
            throw new \RuntimeException('Unable to write disposable lifecycle readiness evidence.');
        }
    }

    private function createDatabase(): void
    {
        if (!preg_match('/^' . self::DATABASE_PREFIX . '[a-f0-9]{16}$/', $this->databaseName)) {
            throw new \LogicException('Refusing an invalid disposable database name.');
        }
        if ($this->databaseName === 'db' || $this->databaseExists()) {
            throw new \RuntimeException('Disposable database boundary is not fresh.');
        }

        $this->adminPdo()->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->databaseCreated = true;
    }

    private function createProject(): void
    {
        if (file_exists($this->projectRoot)) {
            throw new \RuntimeException('Disposable project root already exists.');
        }

        foreach (['config', 'storage', 'templates', 'web/cpresources'] as $relative) {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . $relative;
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("Unable to create disposable path: {$path}");
            }
        }
        $this->projectCreated = true;

        if (!symlink($this->vendorRoot, $this->projectRoot . '/vendor')) {
            throw new \RuntimeException('Unable to link the disposable fixture vendor root.');
        }

        $this->writeOwnedFile('bootstrap.php', <<<'PHP'
<?php
define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require_once CRAFT_VENDOR_PATH . '/autoload.php';
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
}
PHP);
        $this->writeOwnedFile('craft', <<<'PHP'
#!/usr/bin/env php
<?php
require __DIR__ . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
exit($app->run());
PHP);
        chmod($this->projectRoot . '/craft', 0700);
        $this->writeOwnedFile('config/general.php', <<<'PHP'
<?php
use craft\config\GeneralConfig;
return GeneralConfig::create()
    ->allowAdminChanges(true)
    ->devMode(false)
    ->omitScriptNameInUrls();
PHP);
        $this->writeOwnedFile('config/app.php', "<?php\nreturn [\n"
            . "    'id' => 'campaign-manager-fixture-{$this->runId}',\n"
            . "    'aliases' => [\n"
            . "        '@root' => dirname(__DIR__),\n"
            . "        '@webroot' => dirname(__DIR__) . '/web',\n"
            . "        '@web' => '/',\n"
            . "    ],\n"
            . "];\n");
        $this->writeOwnedFile('config/db.php', <<<'PHP'
<?php
use craft\helpers\App;
return [
    'dsn' => App::env('CRAFT_DB_DSN'),
    'user' => App::env('CRAFT_DB_USER'),
    'password' => App::env('CRAFT_DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_0900_ai_ci',
    'schema' => App::env('CRAFT_DB_SCHEMA'),
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX'),
];
PHP);
        $this->writeOwnedFile('.env', implode("\n", [
            'CRAFT_APP_ID=campaign-manager-fixture-' . $this->runId,
            'CRAFT_ENVIRONMENT=test',
            'CRAFT_EDITION=pro',
            'CRAFT_SECURITY_KEY=' . $this->securityKey,
            'CRAFT_DB_DSN=' . $this->fixtureDsn(),
            'CRAFT_DB_USER=' . $this->databaseUser(),
            'CRAFT_DB_PASSWORD=' . $this->databasePassword(),
            'CRAFT_DB_SCHEMA=',
            'CRAFT_DB_TABLE_PREFIX=',
            'PRIMARY_SITE_URL=https://campaign-manager-fixture.example.test',
            'CRAFT_WEB_URL=https://campaign-manager-fixture.example.test',
            'CRAFT_WEB_ROOT=' . $this->projectRoot . '/web',
            '',
        ]));
    }

    private function installCraft(): void
    {
        $this->runCommand([
            PHP_BINARY,
            $this->projectRoot . '/craft',
            'install',
            '--interactive=0',
            '--silent-exit-on-exception=0',
            '--site-name=Campaign Manager Fixture',
            '--site-url=https://campaign-manager-fixture.example.test',
            '--language=en-US',
            '--username=fixture-admin',
            '--email=fixture-admin@example.test',
            '--password=Fixture-Campaign-Manager-2026!',
        ], $this->projectRoot);
    }

    /**
     * The suite's editable-site behavior needs a multi-site install: Craft
     * grants every user the only site of a single-site project, and several
     * families need up to three sites.
     */
    private function createAdditionalSites(): void
    {
        $this->writeOwnedFile('fixture-sites.php', <<<'PHP'
<?php
require __DIR__ . '/bootstrap.php';
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$sites = Craft::$app->getSites();
$primary = $sites->getPrimarySite();
// One handle is mixed-case: Craft allows it, and a CSV import must keep the
// case of a Site column value to match it.
foreach (['de' => ['siteDe', 'German'], 'fr' => ['fr', 'French']] as $language => [$handle, $label]) {
    $site = new craft\models\Site([
        'groupId' => $primary->groupId,
        'name' => 'Campaign Manager Fixture ' . $label,
        'handle' => $handle,
        'language' => $language,
        'hasUrls' => true,
        'baseUrl' => 'https://campaign-manager-fixture.example.test/' . $language . '/',
        'enabled' => true,
    ]);
    if (!$sites->saveSite($site)) {
        fwrite(STDERR, 'Unable to create fixture site ' . $label . ': ' . json_encode($site->getErrors()) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDOUT, 'Fixture sites: ' . count($sites->getAllSites()) . PHP_EOL);
PHP);

        $this->runCommand([PHP_BINARY, $this->projectRoot . '/fixture-sites.php'], $this->projectRoot);
    }

    private function installPlugins(): void
    {
        foreach (['logging-library', 'sms-manager', 'formie', 'formie-rating-field', 'campaign-manager'] as $handle) {
            $this->runCommand([
                PHP_BINARY,
                $this->projectRoot . '/craft',
                'plugin/install',
                $handle,
                '--interactive=0',
                '--silent-exit-on-exception=0',
            ], $this->projectRoot);
        }
    }

    /** @param list<string> $phpunitArguments @return array{command: list<string>, exitCode: int, stdout: string, stderr: string} */
    private function runPhpunit(array $phpunitArguments): array
    {
        return $this->runCommand([
            PHP_BINARY,
            $this->vendorRoot . '/bin/phpunit',
            '--configuration',
            $this->packageRoot . '/phpunit.xml.dist',
            '--colors=never',
            ...$phpunitArguments,
        ], $this->packageRoot);
    }

    /** @param list<string> $command @return array{command: list<string>, exitCode: int, stdout: string, stderr: string} */
    private function runCommand(array $command, string $workingDirectory): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $this->subprocessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start disposable command.');
        }
        $this->activeProcess = $process;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $this->activeProcess = null;
        $result = [
            'command' => $command,
            'exitCode' => $status,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
        $this->commands[] = $result;
        if ($status !== 0) {
            throw new \RuntimeException(
                'Disposable command failed (' . $status . '): ' . implode(' ', $command)
                . "\n" . $result['stdout'] . "\n" . $result['stderr'],
            );
        }

        return $result;
    }

    /** @return array<string, string> */
    private function subprocessEnvironment(): array
    {
        $environment = [];
        foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return array_merge($environment, [
            'CRAFT_APP_ID' => 'campaign-manager-fixture-' . $this->runId,
            'CRAFT_ALLOW_SUPERUSER' => '1',
            'CRAFT_EDITION' => 'pro',
            'CRAFT_ENVIRONMENT' => 'test',
            'CRAFT_SECURITY_KEY' => $this->securityKey,
            'CRAFT_DB_DSN' => $this->fixtureDsn(),
            'CRAFT_DB_USER' => $this->databaseUser(),
            'CRAFT_DB_PASSWORD' => $this->databasePassword(),
            'CRAFT_DB_SCHEMA' => '',
            'CRAFT_DB_TABLE_PREFIX' => '',
            'PRIMARY_SITE_URL' => 'https://campaign-manager-fixture.example.test',
            'CRAFT_WEB_URL' => 'https://campaign-manager-fixture.example.test',
            'CRAFT_WEB_ROOT' => $this->projectRoot . '/web',
            'CAMPAIGN_MANAGER_TEST_PROJECT_ROOT' => $this->projectRoot,
        ]);
    }

    private function fixtureDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $this->databaseHost(),
            $this->environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_PORT', '3306'),
            $this->databaseName,
        );
    }

    private function adminPdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $this->databaseHost(),
                $this->environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_PORT', '3306'),
            ),
            $this->databaseUser(),
            $this->databasePassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function databaseExists(): bool
    {
        $statement = $this->adminPdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name',
        );
        $statement->execute(['name' => $this->databaseName]);

        return (int)$statement->fetchColumn() === 1;
    }

    private function databaseHost(): string
    {
        return $this->environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_HOST', 'db');
    }

    private function databaseUser(): string
    {
        return $this->environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_USER', 'root');
    }

    private function databasePassword(): string
    {
        return $this->environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_PASSWORD', 'root');
    }

    private function environmentValue(string $name, string $default): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function writeOwnedFile(string $relativePath, string $contents): void
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . $relativePath;
        if (!str_starts_with($path, $this->projectRoot . DIRECTORY_SEPARATOR)) {
            throw new \LogicException('Refusing to write outside the disposable project root.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write disposable project file: {$path}");
        }
    }

    private function removeOwnedProjectRoot(): void
    {
        $tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if (!preg_match(
            '#^' . preg_quote($tmpRoot, '#') . '/campaign-manager-fixture-[a-f0-9]{16}$#',
            $this->projectRoot,
        )) {
            throw new \LogicException('Refusing cleanup outside the exact disposable project boundary.');
        }
        if (!file_exists($this->projectRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Unable to remove owned fixture file: ' . $item->getPathname());
                }
            } elseif (!rmdir($item->getPathname())) {
                throw new \RuntimeException('Unable to remove owned fixture directory: ' . $item->getPathname());
            }
        }
        if (!rmdir($this->projectRoot)) {
            throw new \RuntimeException('Unable to remove the disposable project root.');
        }
    }
}
