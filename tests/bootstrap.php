<?php

/**
 * PHPUnit bootstrap for the campaign-manager plugin.
 *
 * Delegates to the shared Base integration bootstrap, which initialises Craft
 * as a console application. Workspace runs use the DDEV Craft project root;
 * the standalone quality gate supplies an exact disposable project root.
 * Tests run against that project's database without transactional rollback,
 * so cleanup is by exact ownership (see `tests/TestCase.php`).
 *
 * @since 5.11.0
 */

declare(strict_types=1);

if (!function_exists('craft_modify_app_config')) {
    function craft_modify_app_config(array &$config, string $appType): void
    {
        \lindemannrock\campaignmanager\tests\Support\TransportIsolation::install();
    }
}

$packageRoot = dirname(__DIR__);
$configuredProjectRoot = $_SERVER['CAMPAIGN_MANAGER_TEST_PROJECT_ROOT']
    ?? $_ENV['CAMPAIGN_MANAGER_TEST_PROJECT_ROOT']
    ?? null;
$projectRoot = is_string($configuredProjectRoot) && $configuredProjectRoot !== ''
    ? $configuredProjectRoot
    : dirname($packageRoot, 2);
$baseBootstrap = $projectRoot . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php';

if (!file_exists($baseBootstrap)) {
    fwrite(STDERR, "Base plugin testing bootstrap not found at {$baseBootstrap}\n");
    fwrite(STDERR, "Run `composer install` and ensure lindemannrock/craft-plugin-base ^5.x is present.\n");
    exit(1);
}

require_once $baseBootstrap;

\lindemannrock\base\testing\bootstrap($projectRoot);
