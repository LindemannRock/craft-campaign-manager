<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\tests\Support\CustomerArchiveContract;
use lindemannrock\campaignmanager\tests\TestCase;
use RuntimeException;

/**
 * Covers the customer package boundary: the Git and Composer archives ship
 * every runtime file and none of the development, internal, or build-input
 * paths, and the archive check cleans its own temporary space either way.
 *
 * @since 5.16.0
 */
final class CustomerArchiveBoundaryTest extends TestCase
{
    public function testGitAndComposerArchivesIncludeEveryRuntimeFile(): void
    {
        $contract = new CustomerArchiveContract($this->packageRoot());
        $result = $contract->run();

        self::assertNotEmpty($result['requiredFiles']);
        foreach (CustomerArchiveContract::REQUIRED_RUNTIME_ASSETS as $asset) {
            self::assertContains($asset, $result['requiredFiles'], $asset);
            self::assertContains($asset, $result['gitMembers'], 'Git archive: ' . $asset);
            self::assertContains($asset, $result['composerMembers'], 'Composer archive: ' . $asset);
        }
        foreach (CustomerArchiveContract::REQUIRED_PACKAGE_FILES as $file) {
            self::assertContains($file, $result['gitMembers'], 'Git archive: ' . $file);
            self::assertContains($file, $result['composerMembers'], 'Composer archive: ' . $file);
        }
        self::assertSame([], $contract->missingRequiredFiles($result['gitMembers'], $result['requiredFiles']));
        self::assertSame([], $contract->missingRequiredFiles($result['composerMembers'], $result['requiredFiles']));
        self::assertSame(['workRootRemoved' => true], $result['cleanup']);
        self::assertDirectoryDoesNotExist($contract->workRoot());
    }

    public function testGitAndComposerArchivesExcludeEveryDevelopmentPath(): void
    {
        $contract = new CustomerArchiveContract($this->packageRoot());
        $result = $contract->run();

        self::assertSame(CustomerArchiveContract::EXCLUDED_DEVELOPMENT_PATHS, $result['excludedPaths']);
        self::assertSame([], $contract->leakedDevelopmentMembers($result['gitMembers']));
        self::assertSame([], $contract->leakedDevelopmentMembers($result['composerMembers']));

        // The paths that exist in this checkout and must be absent as whole
        // subtrees, including their directory entries.
        foreach (['tests', '.github', '.githooks', 'scripts', 'src/web/assets/analytics/src', '.internal', '.phpunit.cache'] as $directory) {
            foreach ([$result['gitMembers'], $result['composerMembers']] as $members) {
                self::assertNotContains($directory, $members, $directory);
                self::assertSame([], array_values(array_filter(
                    $members,
                    static fn(string $member): bool => str_starts_with($member, $directory . '/'),
                )), $directory . '/');
            }
        }
        foreach (['ecs.php', 'phpstan.neon', 'phpunit.xml.dist', 'package.json', 'CLAUDE.md', 'release-please-config.json'] as $file) {
            self::assertNotContains($file, $result['gitMembers'], 'Git archive: ' . $file);
            self::assertNotContains($file, $result['composerMembers'], 'Composer archive: ' . $file);
        }
        self::assertDirectoryDoesNotExist($contract->workRoot());
    }

    public function testBoundaryRejectsLeakedDevelopmentMembersAndMissingRuntimeAssets(): void
    {
        $contract = new CustomerArchiveContract($this->packageRoot());
        $required = $contract->requiredFiles();

        foreach (CustomerArchiveContract::REQUIRED_RUNTIME_ASSETS as $asset) {
            self::assertContains($asset, $required, $asset);
        }

        $leaking = array_merge($required, [
            'tests/TestCase.php',
            '.github/workflows/ci.yml',
            '.githooks/pre-commit',
            'scripts/quality-gate',
            'src/web/assets/analytics/src/analytics.js',
            'src/web/assets/node_modules/.package-lock.json',
            '.internal/audit/pre-release.md',
            'src/.DS_Store',
            'phpunit.xml.dist',
        ]);
        self::assertSame([
            'tests/TestCase.php',
            '.github/workflows/ci.yml',
            '.githooks/pre-commit',
            'scripts/quality-gate',
            'src/web/assets/analytics/src/analytics.js',
            'src/web/assets/node_modules/.package-lock.json',
            '.internal/audit/pre-release.md',
            'src/.DS_Store',
            'phpunit.xml.dist',
        ], $contract->leakedDevelopmentMembers($leaking));

        $withoutAssets = array_values(array_diff($required, CustomerArchiveContract::REQUIRED_RUNTIME_ASSETS));
        $expectedMissing = CustomerArchiveContract::REQUIRED_RUNTIME_ASSETS;
        sort($expectedMissing);
        self::assertSame($expectedMissing, $contract->missingRequiredFiles($withoutAssets, $required));
        self::assertSame([], $contract->missingRequiredFiles($required, $required));
        self::assertSame([], $contract->leakedDevelopmentMembers($required));
    }

    public function testFailureAfterArchiveCreationStillRemovesOwnedDirectory(): void
    {
        $contract = new CustomerArchiveContract($this->packageRoot(), CustomerArchiveContract::FAIL_AFTER_ARCHIVES);

        try {
            $contract->run();
            self::fail('The injected failure should have been thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected customer archive failure after archive creation.', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($contract->workRoot());
        self::assertSame(['workRootRemoved' => true], $contract->cleanup());
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
