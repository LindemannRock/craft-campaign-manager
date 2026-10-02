<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Generates the Git and Composer customer archives of this package into one
 * owned temporary directory and checks both member lists against the package
 * boundary: every runtime file is present and every development path is
 * absent. The owned directory is removed on success and on failure.
 *
 * Shared by the `customer-archive` quality-gate constituent and the archive
 * boundary PHPUnit family so both read the same contract.
 *
 * @internal
 * @since 5.16.0
 */
final class CustomerArchiveContract
{
    public const FAIL_AFTER_ARCHIVES = 'after-archives';

    /**
     * Generated or vendored runtime assets that must survive every exclusion rule.
     */
    public const REQUIRED_RUNTIME_ASSETS = [
        'src/web/assets/analytics/dist/analytics.js',
        'src/resources/js/jquery.min.js',
    ];

    /**
     * Package metadata Composer and consumers need outside src/.
     */
    public const REQUIRED_PACKAGE_FILES = [
        'composer.json',
        'README.md',
        'LICENSE.md',
        'CHANGELOG.md',
        'SECURITY.md',
    ];

    /**
     * Development, internal, and build-input paths that must never ship.
     * A trailing slash means the whole directory subtree.
     */
    public const EXCLUDED_DEVELOPMENT_PATHS = [
        'tests/',
        '.github/',
        '.githooks/',
        'scripts/',
        'docs/',
        '.internal/',
        '.phpunit.cache/',
        'vendor/',
        'src/web/assets/analytics/src/',
        'src/web/assets/node_modules/',
        'src/web/assets/package.json',
        'src/web/assets/package-lock.json',
        'package.json',
        'composer.lock',
        'ecs.php',
        'phpstan.neon',
        'phpunit.xml.dist',
        'release-please-config.json',
        '.release-please-manifest.json',
        'CLAUDE.md',
        '.gitattributes',
        '.gitignore',
    ];

    private const WORK_ROOT_PATTERN = '#/campaign-manager-customer-archive-[a-f0-9]{16}$#';

    private readonly string $packageRoot;

    private readonly string $workRoot;

    public function __construct(string $packageRoot, private readonly ?string $failureStage = null)
    {
        $resolved = realpath($packageRoot);
        if ($resolved === false || !is_file($resolved . '/composer.json') || !is_dir($resolved . '/.git')) {
            throw new LogicException('The customer archive contract needs the package Git checkout root.');
        }

        $this->packageRoot = $resolved;
        $this->workRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . '/campaign-manager-customer-archive-' . bin2hex(random_bytes(8));
    }

    public function workRoot(): string
    {
        return $this->workRoot;
    }

    /**
     * Build both archives, verify them, and remove the owned directory.
     *
     * @return array{
     *     gitMembers: list<string>,
     *     composerMembers: list<string>,
     *     requiredFiles: list<string>,
     *     excludedPaths: list<string>,
     *     cleanup: array{workRootRemoved: bool},
     * }
     */
    public function run(): array
    {
        $original = null;
        $result = null;

        try {
            $result = $this->verify();
        } catch (\Throwable $exception) {
            $original = $exception;
        }

        try {
            $cleanup = $this->cleanup();
        } catch (\Throwable $cleanupFailure) {
            if ($original !== null) {
                throw new RuntimeException(
                    $original->getMessage() . '; archive cleanup also failed: ' . $cleanupFailure->getMessage(),
                    0,
                    $original,
                );
            }

            throw $cleanupFailure;
        }

        if ($original !== null) {
            throw $original;
        }

        $result['cleanup'] = $cleanup;

        return $result;
    }

    /**
     * Remove the owned work directory. Safe to call more than once.
     *
     * @return array{workRootRemoved: bool}
     */
    public function cleanup(): array
    {
        if (preg_match(self::WORK_ROOT_PATTERN, $this->workRoot) !== 1) {
            throw new LogicException('Refusing cleanup outside the owned archive directory.');
        }

        if (file_exists($this->workRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->workRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || $item->isFile()) {
                    if (!unlink($item->getPathname())) {
                        throw new RuntimeException('Unable to remove an owned archive file: ' . $item->getPathname());
                    }
                } elseif (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Unable to remove an owned archive directory: ' . $item->getPathname());
                }
            }
            if (!rmdir($this->workRoot)) {
                throw new RuntimeException('Unable to remove the owned archive directory.');
            }
        }

        return ['workRootRemoved' => !file_exists($this->workRoot)];
    }

    /**
     * Tracked runtime files under src/ plus required package files: every
     * one must be present in both archives.
     *
     * @return list<string>
     */
    public function requiredFiles(): array
    {
        $tracked = preg_split('/\R/', trim($this->runCommand([
            'git', '-c', 'safe.directory=' . $this->packageRoot, 'ls-files', '--', 'src',
        ], $this->packageRoot))) ?: [];

        $required = self::REQUIRED_PACKAGE_FILES;
        foreach ($tracked as $path) {
            if ($path !== '' && !$this->matchesExcludedPath($path)) {
                $required[] = $path;
            }
        }
        foreach (self::REQUIRED_RUNTIME_ASSETS as $asset) {
            if (!in_array($asset, $required, true)) {
                throw new RuntimeException("Required runtime asset is not a tracked package file: {$asset}");
            }
        }

        $required = array_values(array_unique($required));
        sort($required);

        return $required;
    }

    /**
     * @param list<string> $members
     * @return list<string> Required files missing from the member list.
     */
    public function missingRequiredFiles(array $members, ?array $required = null): array
    {
        $missing = [];
        foreach ($required ?? $this->requiredFiles() as $path) {
            if (!in_array($path, $members, true)) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $members
     * @return list<string> Members that fall inside an excluded development path.
     */
    public function leakedDevelopmentMembers(array $members): array
    {
        $leaked = [];
        foreach ($members as $member) {
            if ($this->matchesExcludedPath($member) || basename($member) === '.DS_Store') {
                $leaked[] = $member;
            }
        }

        return $leaked;
    }

    /**
     * @return array{
     *     gitMembers: list<string>,
     *     composerMembers: list<string>,
     *     requiredFiles: list<string>,
     *     excludedPaths: list<string>,
     * }
     */
    private function verify(): array
    {
        $this->createWorkRoot();

        $gitMembers = $this->gitArchiveMembers();
        $composerMembers = $this->composerArchiveMembers();

        if ($this->failureStage === self::FAIL_AFTER_ARCHIVES) {
            throw new RuntimeException('Injected customer archive failure after archive creation.');
        }

        $required = $this->requiredFiles();
        $problems = [];
        foreach (['Git archive' => $gitMembers, 'Composer archive' => $composerMembers] as $label => $members) {
            foreach ($this->missingRequiredFiles($members, $required) as $path) {
                $problems[] = "{$label} is missing required runtime file: {$path}";
            }
            foreach ($this->leakedDevelopmentMembers($members) as $member) {
                $problems[] = "{$label} contains development-only member: {$member}";
            }
        }
        if ($problems !== []) {
            throw new RuntimeException("Customer archive boundary failed:\n" . implode("\n", $problems));
        }

        return [
            'gitMembers' => $gitMembers,
            'composerMembers' => $composerMembers,
            'requiredFiles' => $required,
            'excludedPaths' => self::EXCLUDED_DEVELOPMENT_PATHS,
        ];
    }

    private function createWorkRoot(): void
    {
        if (file_exists($this->workRoot)) {
            throw new RuntimeException('The owned archive directory already exists.');
        }
        if (!mkdir($this->workRoot . '/composer-home', 0700, true)) {
            throw new RuntimeException('Unable to create the owned archive directory.');
        }
    }

    /** @return list<string> */
    private function gitArchiveMembers(): array
    {
        $archive = $this->workRoot . '/git-package.tar';
        $this->runCommand([
            'git',
            '-c',
            'safe.directory=' . $this->packageRoot,
            'archive',
            '--format=tar',
            '--worktree-attributes',
            '--output=' . $archive,
            'HEAD',
        ], $this->packageRoot);

        return $this->archiveMembers($archive);
    }

    /** @return list<string> */
    private function composerArchiveMembers(): array
    {
        $this->runCommand([
            'composer',
            '--no-plugins',
            'archive',
            '--format=tar',
            '--dir=' . $this->workRoot,
            '--file=composer-package',
            '--no-interaction',
            '--no-ansi',
        ], $this->packageRoot, ['COMPOSER_HOME' => $this->workRoot . '/composer-home']);

        return $this->archiveMembers($this->workRoot . '/composer-package.tar');
    }

    /** @return list<string> */
    private function archiveMembers(string $archive): array
    {
        if (!is_file($archive)) {
            throw new RuntimeException("Archive was not created: {$archive}");
        }

        $members = [];
        foreach (preg_split('/\R/', $this->runCommand(['tar', '-tf', $archive], $this->workRoot)) ?: [] as $member) {
            $member = rtrim($member, '/');
            if ($member !== '') {
                $members[] = $member;
            }
        }
        sort($members);

        return $members;
    }

    private function matchesExcludedPath(string $member): bool
    {
        foreach (self::EXCLUDED_DEVELOPMENT_PATHS as $rule) {
            if (str_ends_with($rule, '/') ? str_starts_with($member . '/', $rule) : $member === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function runCommand(array $command, string $workingDirectory, array $environment = []): string
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, $environment === [] ? null : array_merge($this->inheritedEnvironment(), $environment));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start archive command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException(
                'Archive command failed (' . $status . '): ' . implode(' ', $command) . "\n" . $stdout . "\n" . $stderr,
            );
        }

        return is_string($stdout) ? $stdout : '';
    }

    /** @return array<string, string> */
    private function inheritedEnvironment(): array
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
