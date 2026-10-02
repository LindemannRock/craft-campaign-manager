<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\campaignmanager\tests\Support\CustomerArchiveContract;

// Quality-gate constituent: prove both customer archives honour the package
// boundary. Runs without Craft; needs git, composer, and tar on PATH.

$packageRoot = dirname(__DIR__);
require_once $packageRoot . '/tests/Support/CustomerArchiveContract.php';

$contract = new CustomerArchiveContract($packageRoot);

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGHUP, SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function(int $received) use ($contract): never {
            try {
                $contract->cleanup();
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Signal cleanup failed: ' . $exception->getMessage() . PHP_EOL);
            }
            exit(128 + $received);
        });
    }
}

try {
    $result = $contract->run();
    fwrite(STDOUT, sprintf(
        "Customer archive boundary passed: %d Git members, %d Composer members, %d required files present, %d excluded rules enforced, cleanup %s.\n",
        count($result['gitMembers']),
        count($result['composerMembers']),
        count($result['requiredFiles']),
        count($result['excludedPaths']),
        json_encode($result['cleanup'], JSON_THROW_ON_ERROR),
    ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
