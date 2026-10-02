<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\tests\Support\CommandRunner;
use lindemannrock\campaignmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Covers the tracked pre-commit hook: inside the workspace it runs only the
 * read-only DDEV `composer ci`, never touches host PHP or shared vendor
 * binaries, never modifies files, and returns the checked command's exit
 * status. The hook is exercised from an isolated copy with fake executables,
 * so the real worktree and the real DDEV project are never involved.
 *
 * @since 5.16.0
 */
final class PreCommitHookTest extends TestCase
{
    private const FORBIDDEN_TOOLS = ['php', 'composer', 'phpstan', 'ecs', 'phpunit', 'npm', 'node', 'act', 'git'];

    private const EXPECTED_DDEV_COMMAND = "cd plugins/campaign-manager && php -r 'fwrite(STDOUT, \"Campaign Manager pre-commit using DDEV PHP \" . PHP_VERSION . PHP_EOL);' && composer ci";

    public function testWorkspaceHookRunsOnlyReadOnlyDdevComposerCi(): void
    {
        $harness = $this->createWorkspaceHarness(ddevExitStatus: 0);

        $result = $this->runHook($harness, withDdev: true);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Campaign Manager pre-commit checks passed.', $result['stdout']);
        self::assertSame([
            [$harness['workspaceRoot'], 'exec', self::EXPECTED_DDEV_COMMAND],
        ], $this->ddevInvocations($harness));
        self::assertFileDoesNotExist($harness['forbiddenLog'], 'Host PHP, Composer, vendor binaries, and other tools must not run.');
        self::assertSame($harness['fingerprint'], $this->fingerprint($harness['packageRoot']));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function failingStatuses(): iterable
    {
        yield 'ECS or PHPStan violation' => [1];
        yield 'tool crash' => [3];
        yield 'unusual status' => [42];
    }

    #[DataProvider('failingStatuses')]
    public function testWorkspaceHookPropagatesTheCheckFailureWithoutModifyingFiles(int $status): void
    {
        $harness = $this->createWorkspaceHarness(ddevExitStatus: $status);

        $result = $this->runHook($harness, withDdev: true);

        self::assertSame($status, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString("Campaign Manager pre-commit checks failed in DDEV (exit {$status}).", $result['stderr']);
        self::assertStringContainsString('ddev exec "cd plugins/campaign-manager && composer ci"', $result['stderr']);
        self::assertCount(1, $this->ddevInvocations($harness));
        self::assertFileDoesNotExist($harness['forbiddenLog']);
        self::assertSame($harness['fingerprint'], $this->fingerprint($harness['packageRoot']));
    }

    public function testWorkspaceHookFailsClearlyWithoutDdevInsteadOfUsingHostPhp(): void
    {
        $harness = $this->createWorkspaceHarness(ddevExitStatus: 0);

        $result = $this->runHook($harness, withDdev: false);

        self::assertSame(127, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString("'ddev' is unavailable", $result['stderr']);
        self::assertStringContainsString('host PHP is not a supported workspace fallback', $result['stderr']);
        self::assertSame([], $this->ddevInvocations($harness));
        self::assertFileDoesNotExist($harness['forbiddenLog']);
        self::assertSame($harness['fingerprint'], $this->fingerprint($harness['packageRoot']));
    }

    public function testHookNeverInvokesTestsBuildsArchivesActOrAutoFix(): void
    {
        // Only executable lines count; the hook's own comments describe what it avoids.
        $hook = implode("\n", array_filter(
            explode("\n", (string)file_get_contents($this->packageRoot() . '/.githooks/pre-commit')),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '#'),
        ));

        foreach (['--fix', 'fix-cs', 'phpunit', 'composer test', 'ci:full', 'quality-gate', 'run-tests', 'act ', 'archive', 'npm', 'node ', 'vendor/bin'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $hook, $forbidden);
        }
        self::assertSame(1, preg_match_all('/^\s*ddev exec "cd plugins\/campaign-manager && .* && composer ci"\s*$/m', $hook), 'One DDEV invocation.');
        self::assertSame(1, preg_match_all('/^\s*composer ci\s*$/m', $hook), 'One standalone invocation.');
        self::assertSame(0, preg_match_all('/^\s*(phpstan|ecs|\S*vendor\/bin)/m', $hook), 'No direct PHPStan/ECS binary invocation.');
    }

    public function testStandaloneHookValidatesPlatformThenRunsReadOnlyComposerCi(): void
    {
        $harness = $this->createStandaloneHarness(composerCiExitStatus: 0);

        $result = $this->runHook($harness, withDdev: false);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame([
            ['composer', 'check-platform-reqs', '--no-interaction'],
            ['php', 'scripts/check-quality-platform.php'],
            ['composer', 'ci'],
        ], $this->toolInvocations($harness));
        self::assertSame($harness['fingerprint'], $this->fingerprint($harness['packageRoot']));
    }

    public function testStandaloneHookPropagatesComposerCiFailure(): void
    {
        $harness = $this->createStandaloneHarness(composerCiExitStatus: 5);

        $result = $this->runHook($harness, withDdev: false);

        self::assertSame(5, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Campaign Manager pre-commit checks failed (exit 5).', $result['stderr']);
        self::assertSame($harness['fingerprint'], $this->fingerprint($harness['packageRoot']));
    }

    /**
     * Copy the hook into `<tmp>/workspace/plugins/campaign-manager` next to a
     * fake `.ddev/config.yaml`, so the hook takes its workspace branch, with a
     * sample source file whose bytes must survive untouched.
     *
     * @return array{workspaceRoot: string, packageRoot: string, binDirectory: string, ddevLog: string, forbiddenLog: string, fingerprint: array<string, string>}
     */
    private function createWorkspaceHarness(int $ddevExitStatus): array
    {
        $root = $this->createTrackedTempDirectory('campaign-manager-hook-');
        $workspaceRoot = $root . '/workspace';
        $packageRoot = $workspaceRoot . '/plugins/campaign-manager';
        $binDirectory = $root . '/bin';
        $ddevLog = $root . '/ddev.log';
        $forbiddenLog = $root . '/forbidden.log';

        foreach ([$workspaceRoot . '/.ddev', $packageRoot . '/.githooks', $packageRoot . '/src', $binDirectory] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true), $directory);
        }
        self::assertNotFalse(file_put_contents($workspaceRoot . '/.ddev/config.yaml', "name: campaign-manager-hook-test\n"));
        self::assertNotFalse(copy($this->packageRoot() . '/.githooks/pre-commit', $packageRoot . '/.githooks/pre-commit'));
        self::assertTrue(chmod($packageRoot . '/.githooks/pre-commit', 0700));
        // A style violation a mutating hook would "fix".
        self::assertNotFalse(file_put_contents($packageRoot . '/src/Sample.php', "<?php\nclass  Sample{   }\n"));

        $this->writeExecutable(
            $binDirectory . '/ddev',
            "#!/bin/sh\nprintf '%s\\n' \"\$PWD\" \"\$@\" >> \"{$ddevLog}\"\nprintf '%s\\n' '--' >> \"{$ddevLog}\"\nexit {$ddevExitStatus}\n",
        );
        foreach (self::FORBIDDEN_TOOLS as $tool) {
            $this->writeExecutable($binDirectory . '/' . $tool, "#!/bin/sh\nprintf '%s\\n' \"{$tool}\" \"\$@\" >> \"{$forbiddenLog}\"\nexit 99\n");
        }

        return [
            'workspaceRoot' => $workspaceRoot,
            'packageRoot' => $packageRoot,
            'binDirectory' => $binDirectory,
            'ddevLog' => $ddevLog,
            'forbiddenLog' => $forbiddenLog,
            'fingerprint' => $this->fingerprint($packageRoot),
        ];
    }

    /**
     * Copy the hook into a layout without a workspace `.ddev/config.yaml`, with
     * fake `php` and `composer` executables that record their arguments.
     *
     * @return array{workspaceRoot: string, packageRoot: string, binDirectory: string, ddevLog: string, forbiddenLog: string, toolLog: string, fingerprint: array<string, string>}
     */
    private function createStandaloneHarness(int $composerCiExitStatus): array
    {
        $root = $this->createTrackedTempDirectory('campaign-manager-hook-');
        $packageRoot = $root . '/clones/campaign-manager';
        $binDirectory = $root . '/bin';
        $toolLog = $root . '/tools.log';

        foreach ([$packageRoot . '/.githooks', $packageRoot . '/scripts', $packageRoot . '/src', $binDirectory] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true), $directory);
        }
        self::assertNotFalse(copy($this->packageRoot() . '/.githooks/pre-commit', $packageRoot . '/.githooks/pre-commit'));
        self::assertTrue(chmod($packageRoot . '/.githooks/pre-commit', 0700));
        self::assertNotFalse(file_put_contents($packageRoot . '/scripts/check-quality-platform.php', "<?php\n"));
        self::assertNotFalse(file_put_contents($packageRoot . '/src/Sample.php', "<?php\nclass  Sample{   }\n"));

        $this->writeExecutable(
            $binDirectory . '/composer',
            "#!/bin/sh\nprintf '%s\\n' composer \"\$@\" >> \"{$toolLog}\"\nprintf '%s\\n' '--' >> \"{$toolLog}\"\n"
            . "if [ \"\$1\" = \"ci\" ]; then exit {$composerCiExitStatus}; fi\nexit 0\n",
        );
        $this->writeExecutable(
            $binDirectory . '/php',
            "#!/bin/sh\nprintf '%s\\n' php \"\$@\" >> \"{$toolLog}\"\nprintf '%s\\n' '--' >> \"{$toolLog}\"\nexit 0\n",
        );

        return [
            'workspaceRoot' => $root,
            'packageRoot' => $packageRoot,
            'binDirectory' => $binDirectory,
            'ddevLog' => $root . '/ddev.log',
            'forbiddenLog' => $root . '/forbidden.log',
            'toolLog' => $toolLog,
            'fingerprint' => $this->fingerprint($packageRoot),
        ];
    }

    /**
     * @param array{packageRoot: string, binDirectory: string} $harness
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runHook(array $harness, bool $withDdev): array
    {
        $binDirectory = $harness['binDirectory'];
        if (!$withDdev && is_file($binDirectory . '/ddev')) {
            self::assertTrue(unlink($binDirectory . '/ddev'));
        }

        // Only the fake executables and the system shell utilities are visible.
        $environment = [
            'PATH' => $binDirectory . ':/usr/bin:/bin',
            'HOME' => $harness['packageRoot'],
            'LANG' => 'C',
        ];

        return CommandRunner::run(['bash', $harness['packageRoot'] . '/.githooks/pre-commit'], $harness['packageRoot'], $environment);
    }

    /**
     * @param array{ddevLog: string} $harness
     * @return list<list<string>>
     */
    private function ddevInvocations(array $harness): array
    {
        return $this->invocations($harness['ddevLog']);
    }

    /**
     * @param array{toolLog: string} $harness
     * @return list<list<string>>
     */
    private function toolInvocations(array $harness): array
    {
        return $this->invocations($harness['toolLog']);
    }

    /**
     * @return list<list<string>>
     */
    private function invocations(string $log): array
    {
        if (!is_file($log)) {
            return [];
        }

        $invocations = [];
        $current = [];
        foreach (explode("\n", rtrim((string)file_get_contents($log), "\n")) as $line) {
            if ($line === '--') {
                $invocations[] = $current;
                $current = [];
                continue;
            }
            $current[] = $line;
        }

        return $invocations;
    }

    /**
     * SHA-256 of every file in the harness package, keyed by relative path.
     *
     * @return array<string, string>
     */
    private function fingerprint(string $packageRoot): array
    {
        $fingerprint = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($packageRoot) + 1);
                $fingerprint[$relative] = (string)hash_file('sha256', $file->getPathname());
            }
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
