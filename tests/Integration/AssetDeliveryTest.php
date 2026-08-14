<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\db\Connection;
use craft\web\AssetManager;
use lindemannrock\base\web\assets\analytics\AnalyticsAsset as BaseAnalyticsAsset;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\tests\TestCase;
use lindemannrock\campaignmanager\web\assets\analytics\AnalyticsAsset;

/**
 * Covers static asset delivery for Campaign Manager analytics.
 *
 * @since 5.16.0
 */
final class AssetDeliveryTest extends TestCase
{
    public function testComposerAliasResolvesToInstalledPackageAssets(): void
    {
        $pluginInfo = Craft::$app->getPlugins()->getComposerPluginInfo('campaign-manager');
        self::assertIsArray($pluginInfo);

        $aliases = $pluginInfo['aliases'] ?? null;
        self::assertIsArray($aliases);

        $configuredAliasRoot = $aliases['@lindemannrock/campaignmanager'] ?? null;
        self::assertIsString($configuredAliasRoot);

        $packageRoot = dirname(__DIR__, 2);
        $sourceRoot = realpath($packageRoot . '/src');
        $composerAliasRoot = realpath($configuredAliasRoot);
        $runtimeAliasRoot = realpath(Craft::getAlias('@lindemannrock/campaignmanager'));

        self::assertIsString($sourceRoot);
        self::assertSame($sourceRoot, $composerAliasRoot);
        self::assertSame($sourceRoot, $runtimeAliasRoot);
        self::assertDirectoryExists($runtimeAliasRoot . '/web/assets/analytics/dist');
        self::assertFileExists($runtimeAliasRoot . '/web/assets/analytics/dist/analytics.js');
    }

    public function testAnalyticsBundleResolvesAliasWithoutPluginDatabaseOrPublicationState(): void
    {
        $originalAlias = Craft::getAlias('@lindemannrock/campaignmanager');
        $originalAssetManager = Craft::$app->getAssetManager();
        $originalDb = Craft::$app->getDb();
        $originalPlugin = CampaignManager::$plugin;
        $aliasRoot = $this->createTrackedTempDirectory('campaign-manager-asset-package-');
        $offlineDb = new Connection([
            'dsn' => 'unsupported:campaign-manager-asset-test',
        ]);
        $offlineAssetManager = new class([ 'basePath' => $aliasRoot, 'baseUrl' => '/unavailable-runtime-assets', ]) extends AssetManager {
            /** @var list<string> */
            public array $publicationPaths = [];

            /**
             * @inheritdoc
             */
            public function publish($path, $options = []): array
            {
                $this->publicationPaths[] = Craft::getAlias($path);

                throw new \RuntimeException('Runtime asset publication is unavailable.');
            }
        };

        try {
            Craft::setAlias('@lindemannrock/campaignmanager', $aliasRoot);
            Craft::$app->set('assetManager', $offlineAssetManager);
            Craft::$app->set('db', $offlineDb);
            CampaignManager::$plugin = null;

            $bundle = new AnalyticsAsset();

            self::assertNull(CampaignManager::$plugin);
            self::assertFalse($offlineDb->getIsActive());
            self::assertSame([], $offlineAssetManager->publicationPaths);
            self::assertSame($aliasRoot . '/web/assets/analytics/dist', $bundle->sourcePath);
            self::assertSame(['analytics.js'], $bundle->js);
            self::assertSame([], $bundle->css);
            self::assertSame([BaseAnalyticsAsset::class], $bundle->depends);
            self::assertSame([], $bundle->jsOptions);
            self::assertSame([], $bundle->cssOptions);
        } finally {
            CampaignManager::$plugin = $originalPlugin;
            Craft::$app->set('db', $originalDb);
            Craft::$app->set('assetManager', $originalAssetManager);
            Craft::setAlias('@lindemannrock/campaignmanager', $originalAlias);
        }
    }

    public function testConfiguredCdnUrlsRenderOnceWithBaseDependencyFirst(): void
    {
        $originalAssetManager = Craft::$app->getAssetManager();
        $view = Craft::$app->getView();
        $sourceRoot = Craft::getAlias('@lindemannrock/campaignmanager');
        $baseSourceRoot = Craft::getAlias('@lindemannrock/base');
        $baseAnalyticsUrl = 'https://cdn.example.test/base/analytics';
        $campaignAnalyticsUrl = 'https://cdn.example.test/campaign-manager/analytics';
        $assetManager = new class([ 'basePath' => $sourceRoot, 'baseUrl' => '/unavailable-runtime-assets', 'appendTimestamp' => false, 'bundles' => [ BaseAnalyticsAsset::class => [ 'basePath' => $baseSourceRoot . '/web/assets/analytics/dist', 'baseUrl' => $baseAnalyticsUrl, ], AnalyticsAsset::class => [ 'basePath' => $sourceRoot . '/web/assets/analytics/dist', 'baseUrl' => $campaignAnalyticsUrl, ], ], ]) extends AssetManager {
            /** @var list<string> */
            public array $publicationPaths = [];

            /**
             * @inheritdoc
             */
            public function publish($path, $options = []): array
            {
                $this->publicationPaths[] = Craft::getAlias($path);

                throw new \RuntimeException('Runtime asset publication is unavailable.');
            }
        };

        try {
            Craft::$app->set('assetManager', $assetManager);
            $view->clear();

            $view->registerAssetBundle(AnalyticsAsset::class);
            $view->registerAssetBundle(AnalyticsAsset::class);

            self::assertSame([], $assetManager->publicationPaths);
            self::assertSame(
                [AnalyticsAsset::class, BaseAnalyticsAsset::class],
                array_keys($view->assetBundles),
            );
            self::assertSame($baseAnalyticsUrl, $view->assetBundles[BaseAnalyticsAsset::class]->baseUrl);
            self::assertSame($campaignAnalyticsUrl, $view->assetBundles[AnalyticsAsset::class]->baseUrl);

            $headHtml = $view->getHeadHtml(false);
            $bodyHtml = $view->getBodyHtml(false);
            $headScriptCount = preg_match_all('/<script[^>]+src="([^"]+)"[^>]*><\/script>/', $headHtml);
            $bodyScriptCount = preg_match_all('/<script[^>]+src="([^"]+)"[^>]*><\/script>/', $bodyHtml, $scriptMatches);
            $scriptUrls = array_map(static fn(string $url): string => html_entity_decode($url), $scriptMatches[1]);

            self::assertSame(0, $headScriptCount, $headHtml);
            self::assertSame(3, $bodyScriptCount, $bodyHtml);
            self::assertSame([
                $baseAnalyticsUrl . '/js/chart.umd.min.js',
                $baseAnalyticsUrl . '/js/analytics.js',
                $campaignAnalyticsUrl . '/analytics.js',
            ], $scriptUrls, $bodyHtml);
            self::assertSame([], $assetManager->publicationPaths);
        } finally {
            $view->clear();
            Craft::$app->set('assetManager', $originalAssetManager);
        }
    }

    public function testCustomerArchivesIncludeEveryBundleAsset(): void
    {
        $expected = [
            'src/web/assets/analytics/dist/analytics.js',
        ];
        $packageRoot = dirname(__DIR__, 2);
        $archiveRoot = $this->createTrackedTempDirectory('campaign-manager-asset-archive-');
        $composerHome = $archiveRoot . '/composer-home';
        $gitArchive = $archiveRoot . '/git-package.tar';
        $composerArchive = $archiveRoot . '/composer-package.tar';

        self::assertTrue(mkdir($composerHome));

        foreach ($expected as $path) {
            self::assertFileExists($packageRoot . '/' . $path, $path);
        }

        $this->runProcess([
            'git',
            '-c',
            'safe.directory=' . $packageRoot,
            'archive',
            '--worktree-attributes',
            '--output=' . $gitArchive,
            'HEAD',
        ], $packageRoot);
        $this->runProcess([
            '/usr/bin/env',
            'COMPOSER_HOME=' . $composerHome,
            'composer',
            'archive',
            '--format=tar',
            '--dir=' . $archiveRoot,
            '--file=composer-package',
            '--no-interaction',
            '--no-ansi',
        ], $packageRoot);

        $gitMembers = array_filter(explode("\n", $this->runProcess(['tar', '-tf', $gitArchive], $packageRoot)));
        $composerMembers = array_filter(explode("\n", $this->runProcess(['tar', '-tf', $composerArchive], $packageRoot)));

        foreach ($expected as $path) {
            self::assertContains($path, $gitMembers, 'Git archive: ' . $path);
            self::assertContains($path, $composerMembers, 'Composer archive: ' . $path);
        }
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $workingDirectory): string
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertIsString($output);
        self::assertIsString($error);
        self::assertSame(0, proc_close($process), $error);

        return $output;
    }
}
