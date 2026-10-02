<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use RuntimeException;

/**
 * Runs one subprocess to completion and returns its exit status and output
 * without interpreting either, so tests can assert exact propagation.
 *
 * @internal
 * @since 5.16.0
 */
final class CommandRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment Full environment for the
     *   child, or null to inherit the current process environment.
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(array $command, string $workingDirectory, ?array $environment = null): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /**
     * The current process environment as string pairs, for building a child
     * environment that replaces PATH or adds variables.
     *
     * @return array<string, string>
     */
    public static function inheritedEnvironment(): array
    {
        $environment = [];
        foreach (array_merge($_ENV, $_SERVER) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
