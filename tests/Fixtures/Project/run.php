<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\campaignmanager\tests\Support\DisposableCraftProject;

$packageRoot = dirname(__DIR__, 3);
$vendorRoot = $_SERVER['CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT']
    ?? $_ENV['CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT']
    ?? null;
if (!is_string($vendorRoot) || $vendorRoot === '') {
    fwrite(STDERR, "CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT must be set.\n");
    exit(2);
}

require rtrim($vendorRoot, DIRECTORY_SEPARATOR) . '/autoload.php';
require_once $packageRoot . '/tests/Support/DisposableCraftProject.php';

try {
    if (($argv[1] ?? null) === '--interrupt-child') {
        $readyPath = $argv[2] ?? null;
        if (!is_string($readyPath) || $readyPath === '') {
            throw new InvalidArgumentException('Interruption child requires a readiness path.');
        }
        (new DisposableCraftProject($packageRoot, $vendorRoot))->waitForInterruption($readyPath);
    }
    if (($argv[1] ?? null) === '--shutdown-child') {
        $readyPath = $argv[2] ?? null;
        if (!is_string($readyPath) || $readyPath === '') {
            throw new InvalidArgumentException('Shutdown child requires a readiness path.');
        }
        (new DisposableCraftProject($packageRoot, $vendorRoot))->exitThroughShutdown($readyPath);
    }

    if (($argv[1] ?? null) === '--lifecycle-probe') {
        $result = lifecycleProbe($packageRoot, __FILE__, $vendorRoot);
    } else {
        $result = (new DisposableCraftProject($packageRoot, $vendorRoot))->run(array_slice($argv, 1));
    }
    if (isset($result['phpunit'])) {
        fwrite(STDOUT, $result['phpunit']['stdout']);
        if ($result['phpunit']['stderr'] !== '') {
            fwrite(STDERR, $result['phpunit']['stderr']);
        }
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function lifecycleProbe(string $packageRoot, string $runnerPath, string $vendorRoot): array
{
    $failure = (new DisposableCraftProject($packageRoot, $vendorRoot))->runFailureProbe();
    $expectedCleanup = ['projectRemoved' => true, 'databaseRemoved' => true];
    if ($failure['failure'] !== 'Synthetic disposable runner failure.'
        || $failure['cleanup'] !== $expectedCleanup) {
        throw new RuntimeException('Ordinary failure cleanup probe did not prove exact cleanup.');
    }
    if (!function_exists('pcntl_signal')) {
        throw new RuntimeException('The disposable interruption contract requires the pcntl extension.');
    }

    $shutdown = runLifecycleChild($packageRoot, $runnerPath, $vendorRoot, '--shutdown-child', 74, null);
    $interruption = runLifecycleChild($packageRoot, $runnerPath, $vendorRoot, '--interrupt-child', 143, SIGTERM);

    return [
        'failureCleanup' => $failure['cleanup'],
        'shutdownCleanup' => $shutdown,
        'interruptionCleanup' => $interruption,
    ];
}

/** @return array{exitCode: int, projectRemoved: bool, databaseRemoved: bool} */
function runLifecycleChild(
    string $packageRoot,
    string $runnerPath,
    string $vendorRoot,
    string $mode,
    int $expectedStatus,
    ?int $signal,
): array {
    $readyPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . '/campaign-manager-lifecycle-' . bin2hex(random_bytes(8)) . '.json';
    $environment = [];
    foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $environment[$name] = $value;
        }
    }
    $environment[DisposableCraftProject::SOURCE_VENDOR_ENV] = $vendorRoot;

    $process = proc_open([PHP_BINARY, $runnerPath, $mode, $readyPath], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $packageRoot, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start disposable lifecycle child.');
    }
    fclose($pipes[0]);
    $identity = null;

    try {
        $deadline = microtime(true) + 15.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        if (!is_file($readyPath)) {
            throw new RuntimeException('Disposable lifecycle child did not become ready.');
        }

        $identity = validateLifecycleIdentity((string)file_get_contents($readyPath));
        if ($signal !== null) {
            proc_terminate($process, $signal);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== $expectedStatus) {
            throw new RuntimeException(
                "Disposable lifecycle child returned {$status}; expected {$expectedStatus}.\n{$stdout}\n{$stderr}",
            );
        }
        if (lifecycleDatabaseExists($identity['databaseName']) || file_exists($identity['projectRoot'])) {
            throw new RuntimeException("Disposable lifecycle cleanup left owned resources.\n{$stdout}\n{$stderr}");
        }

        return [
            'exitCode' => $status,
            'projectRemoved' => true,
            'databaseRemoved' => true,
        ];
    } finally {
        if (is_resource($process)) {
            @proc_terminate($process, SIGKILL);
            @proc_close($process);
        }
        if (is_file($readyPath)) {
            @unlink($readyPath);
        }
        if (is_array($identity)) {
            cleanupLifecycleResidue($identity);
        }
    }
}

/** @return array{databaseName: string, projectRoot: string} */
function validateLifecycleIdentity(string $payload): array
{
    $identity = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $projectPattern = '#^' . preg_quote($tmpRoot, '#')
        . '/campaign-manager-fixture-[a-f0-9]{16}$#';
    if (!is_array($identity)
        || !is_string($identity['databaseName'] ?? null)
        || preg_match('/^cm_qg_[a-f0-9]{16}$/', $identity['databaseName']) !== 1
        || !is_string($identity['projectRoot'] ?? null)
        || preg_match($projectPattern, $identity['projectRoot']) !== 1) {
        throw new RuntimeException('Disposable lifecycle child reported an unsafe resource identity.');
    }

    return [
        'databaseName' => $identity['databaseName'],
        'projectRoot' => $identity['projectRoot'],
    ];
}

function lifecycleDatabaseExists(string $databaseName): bool
{
    $statement = lifecycleAdminPdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name',
    );
    $statement->execute(['name' => $databaseName]);

    return (int)$statement->fetchColumn() === 1;
}

function cleanupLifecycleResidue(array $identity): void
{
    if (lifecycleDatabaseExists($identity['databaseName'])) {
        lifecycleAdminPdo()->exec('DROP DATABASE `' . $identity['databaseName'] . '`');
    }
    if (file_exists($identity['projectRoot'])) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($identity['projectRoot'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($identity['projectRoot']);
    }
}

function lifecycleAdminPdo(): PDO
{
    $host = environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_HOST', 'db');
    $port = environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_PORT', '3306');
    $user = environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_USER', 'root');
    $password = environmentValue('CAMPAIGN_MANAGER_FIXTURE_DB_PASSWORD', 'root');

    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function environmentValue(string $name, string $default): string
{
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : $default;
}
