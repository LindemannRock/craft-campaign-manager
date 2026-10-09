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
 * Covers the package quality-gate authority: `composer quality-gate` runs
 * every declared constituent exactly once in order, the first failure stops
 * the gate with its own exit status, static analysis and style cover the test
 * tree, and CI and Act delegate to that same command.
 *
 * @since 5.16.0
 */
final class QualityGateOrchestrationTest extends TestCase
{
    private const CONSTITUENTS = [
        'package-validation' => 'package-metadata',
        'platform-compatibility' => 'platform',
        'composer-audit' => 'security-audit',
        'php-quality' => 'php-static-style',
        'phpunit' => 'php-runtime-and-orchestration',
        'customer-archive' => 'package-export',
        'ci-contract' => 'ci-act-orchestration',
    ];

    public function testQualityGateDeclaresEveryRequiredConstituentInOrder(): void
    {
        $result = $this->runInPackage(['bash', 'scripts/quality-gate', '--list']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);

        $declared = [];
        foreach (array_filter(explode("\n", trim($result['stdout']))) as $line) {
            [$id, $family] = explode("\t", $line);
            $declared[$id] = $family;
        }
        self::assertSame(self::CONSTITUENTS, $declared);
    }

    public function testQualityGateInvokesEachConstituentExactlyOnceInOrder(): void
    {
        $harness = $this->createTrackedTempDirectory('campaign-manager-quality-gate-');
        $probe = $this->writeProbe($harness, failingId: null, failingStatus: 0);

        $result = $this->runInPackage(['bash', 'scripts/quality-gate', '--probe', $probe]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame($this->expectedInvocations(array_keys(self::CONSTITUENTS)), $this->recordedInvocations($harness));
        self::assertStringContainsString('Complete Campaign Manager quality gate passed.', $result['stdout']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function constituentIds(): iterable
    {
        foreach (array_keys(self::CONSTITUENTS) as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('constituentIds')]
    public function testFailingConstituentStopsTheGateWithItsOwnExitStatus(string $failingId): void
    {
        $harness = $this->createTrackedTempDirectory('campaign-manager-quality-gate-');
        $probe = $this->writeProbe($harness, failingId: $failingId, failingStatus: 37);

        $result = $this->runInPackage(['bash', 'scripts/quality-gate', '--probe', $probe]);

        $ids = array_keys(self::CONSTITUENTS);
        $expected = array_slice($ids, 0, (int)array_search($failingId, $ids, true) + 1);

        self::assertSame(37, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame($this->expectedInvocations($expected), $this->recordedInvocations($harness));
        self::assertStringContainsString("{$failingId} failed with exit 37.", $result['stderr']);
        self::assertStringNotContainsString('quality gate passed', $result['stdout']);
    }

    public function testProbeMustBeAnAbsoluteExecutable(): void
    {
        $result = $this->runInPackage(['bash', 'scripts/quality-gate', '--probe', 'scripts/quality-gate']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('--probe requires an absolute executable path.', $result['stderr']);
    }

    public function testComposerScriptsDelegateToThePackageAuthority(): void
    {
        $composer = json_decode((string)file_get_contents($this->packageRoot() . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'];

        self::assertSame(['Composer\\Config::disableProcessTimeout', 'bash scripts/quality-gate'], $scripts['quality-gate']);
        self::assertSame(['@phpstan', '@check-cs'], $scripts['ci']);
        self::assertSame(['@ci', '@test'], $scripts['ci:full']);
        self::assertSame('php tests/Fixtures/Project/run.php --no-progress', $scripts['test']);
        self::assertSame('phpunit --colors=always', $scripts['test:direct']);
        self::assertStringStartsWith('ecs check --ansi', $scripts['check-cs']);
        self::assertStringNotContainsString('--fix', $scripts['check-cs']);
    }

    public function testQualityGateRunsStaticStyleTestsAndArchiveConstituents(): void
    {
        $script = (string)file_get_contents($this->packageRoot() . '/scripts/quality-gate');

        self::assertMatchesRegularExpression('/php-quality\)\s+composer ci\s/', $script);
        self::assertMatchesRegularExpression('/phpunit\)\s+bash scripts\/run-tests\s/', $script);
        self::assertMatchesRegularExpression('/customer-archive\)\s+php scripts\/check-customer-archive\.php\s/', $script);
        self::assertMatchesRegularExpression('/ci-contract\)\s+bash scripts\/check-ci-workflow \.github\/workflows\/ci\.yml\s/', $script);
        self::assertStringNotContainsString('test-craft-compat', $script);
        self::assertStringNotContainsString('--php-version', $script);

        $runTests = (string)file_get_contents($this->packageRoot() . '/scripts/run-tests');
        self::assertStringContainsString('composer test', $runTests);
        self::assertStringContainsString('php tests/Fixtures/Project/run.php', $runTests);
        self::assertStringContainsString('--lifecycle-probe', $runTests);
        self::assertLessThan(
            strpos($runTests, 'composer test'),
            strpos($runTests, 'export CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT='),
        );
    }

    public function testStaticAnalysisAndStyleCoverSourceAndTests(): void
    {
        $phpstan = (string)file_get_contents($this->packageRoot() . '/phpstan.neon');
        self::assertMatchesRegularExpression('/paths:\s*\n\s*- src\s*\n\s*- tests\s*\n/', $phpstan);
        self::assertStringContainsString('level: 5', $phpstan);
        self::assertStringContainsString('phpVersion: 80300', $phpstan);

        $ecs = (string)file_get_contents($this->packageRoot() . '/ecs.php');
        self::assertStringContainsString("__DIR__ . '/src'", $ecs);
        self::assertStringContainsString("__DIR__ . '/tests'", $ecs);
    }

    public function testCiWorkflowDelegatesToTheQualityGate(): void
    {
        $workflow = (string)file_get_contents($this->packageRoot() . '/.github/workflows/ci.yml');
        self::assertSame(1, preg_match_all('/^\s*run: composer quality-gate\s*$/m', $workflow));
        self::assertStringNotContainsString('run: composer phpstan', $workflow);
        self::assertStringNotContainsString('run: composer check-cs', $workflow);
        self::assertStringNotContainsString('run: composer test', $workflow);
        self::assertStringNotContainsString('run: composer audit', $workflow);

        $result = $this->runInPackage(['bash', 'scripts/check-ci-workflow', '.github/workflows/ci.yml']);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidWorkflows(): iterable
    {
        $valid = (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        yield 'duplicated constituent' => [
            str_replace("      - name: Complete package quality gate\n", "      - name: PHPStan\n        run: composer phpstan\n\n      - name: Complete package quality gate\n", $valid),
            'without partial duplicate constituents',
        ];
        yield 'missing quality gate' => [
            str_replace('run: composer quality-gate', 'run: composer ci:full', $valid),
            "must invoke 'composer quality-gate' exactly once",
        ];
        yield 'wildcard trust' => [
            str_replace('safe.directory "$GITHUB_WORKSPACE"', "safe.directory '*'", $valid),
            'wildcard safe.directory',
        ];
    }

    #[DataProvider('invalidWorkflows')]
    public function testCiContractRejectsWorkflowsThatBypassTheQualityGate(string $workflow, string $expectedError): void
    {
        $harness = $this->createTrackedTempDirectory('campaign-manager-ci-contract-');
        $path = $harness . '/ci.yml';
        self::assertNotFalse(file_put_contents($path, $workflow));

        $result = $this->runInPackage(['bash', 'scripts/check-ci-workflow', $path]);

        self::assertSame(1, $result['exitCode'], $result['stdout']);
        self::assertStringContainsString($expectedError, $result['stderr']);
    }

    public function testActRunnerExercisesTheCiQualityGateJobAndPropagatesActStatus(): void
    {
        $harness = $this->createTrackedTempDirectory('campaign-manager-act-');
        $binDirectory = $harness . '/bin';
        $log = $harness . '/act.log';
        self::assertTrue(mkdir($binDirectory, 0700));
        $this->writeExecutable($binDirectory . '/act', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"{$log}\"\nexit 23\n");

        $environment = CommandRunner::inheritedEnvironment();
        $environment['PATH'] = $binDirectory . ':' . ($environment['PATH'] ?? '/usr/bin:/bin');
        $result = CommandRunner::run(['bash', 'scripts/act-quality-gates'], $this->packageRoot(), $environment);

        self::assertSame(23, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('CI workflow contract passed.', $result['stdout']);
        $arguments = array_filter(explode("\n", (string)file_get_contents($log)));
        self::assertSame([
            'push',
            '-W',
            $this->packageRoot() . '/.github/workflows/ci.yml',
            '-j',
            'quality-gates',
            '-P',
            'ubuntu-latest=ghcr.io/catthehacker/ubuntu:act-latest',
            '--container-architecture',
            'linux/amd64',
            '--rm',
        ], array_values($arguments));
    }

    public function testActRunnerRefusesAWorkflowThatBypassesTheQualityGate(): void
    {
        $harness = $this->createTrackedTempDirectory('campaign-manager-act-');
        $binDirectory = $harness . '/bin';
        $log = $harness . '/act.log';
        self::assertTrue(mkdir($binDirectory, 0700));
        $this->writeExecutable($binDirectory . '/act', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"{$log}\"\nexit 0\n");
        $workflow = str_replace(
            'run: composer quality-gate',
            'run: composer ci',
            (string)file_get_contents($this->packageRoot() . '/.github/workflows/ci.yml'),
        );
        self::assertNotFalse(file_put_contents($harness . '/ci.yml', $workflow));

        $environment = CommandRunner::inheritedEnvironment();
        $environment['PATH'] = $binDirectory . ':' . ($environment['PATH'] ?? '/usr/bin:/bin');
        $environment['CAMPAIGN_MANAGER_ACT_WORKFLOW'] = $harness . '/ci.yml';
        $result = CommandRunner::run(['bash', 'scripts/act-quality-gates'], $this->packageRoot(), $environment);

        self::assertSame(1, $result['exitCode'], $result['stdout']);
        self::assertStringContainsString("must invoke 'composer quality-gate' exactly once", $result['stderr']);
        self::assertFileDoesNotExist($log, 'Act must not run when the workflow fails its contract.');
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runInPackage(array $command): array
    {
        return CommandRunner::run($command, $this->packageRoot());
    }

    private function writeProbe(string $harness, ?string $failingId, int $failingStatus): string
    {
        $log = $harness . '/invocations.log';
        $script = "#!/bin/sh\nprintf '%s %s\\n' \"\$1\" \"\$2\" >> \"{$log}\"\n";
        if ($failingId !== null) {
            $script .= "if [ \"\$1\" = \"{$failingId}\" ]; then exit {$failingStatus}; fi\n";
        }
        $script .= "exit 0\n";
        $probe = $harness . '/probe';
        $this->writeExecutable($probe, $script);

        return $probe;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function expectedInvocations(array $ids): array
    {
        return array_map(static fn(string $id): string => $id . ' ' . self::CONSTITUENTS[$id], $ids);
    }

    /**
     * @return list<string>
     */
    private function recordedInvocations(string $harness): array
    {
        $log = $harness . '/invocations.log';
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string)file_get_contents($log))));
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
