<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\web\AssetManager;
use craft\web\View;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use RuntimeException;

/**
 * Rendering a control panel page registers scripts, styles and asset bundles
 * on the application's View, publishes the bundles through the application's
 * AssetManager into the project's published-assets directory, and binds the
 * Twig `craft` variable to whichever application is installed when the View
 * first creates its Twig environment.
 *
 * A test renders its page through a View and an AssetManager of its own
 * instead. The View is a copy of the application's, so it carries the same
 * Twig extensions, hooks and template roots, with nothing registered in
 * Yii's public arrays or in Craft's registered-bundle and registered-script
 * sets, and a fresh Twig environment that compiles in memory; the AssetManager publishes
 * into a directory the test owns. Both answer as the application's `view`
 * and `assetManager` components only while the work runs, placed in front
 * of the real ones by the {@see SessionApplication} proxy that is installed
 * as `Craft::$app`; the real application's service locator is never
 * written. Afterwards the overrides are dropped and the owned directory is
 * removed, so the application, its View, its AssetManager and the project
 * are as they were.
 *
 * @since 5.16.0
 */
trait IsolatedPageRendering
{
    private ?string $isolatedPublishPath = null;

    /**
     * Run the work with an isolated page View and AssetManager answering as
     * the application's `view` and `assetManager` components, then drop
     * them and remove the owned published-assets directory, whether the
     * work returned or threw.
     *
     * @template T
     * @param callable(View): T $work
     * @return T
     */
    protected function withIsolatedPageComponents(callable $work): mixed
    {
        if ($this->isolatedPublishPath !== null) {
            throw new RuntimeException('Isolated page components are already installed.');
        }

        $application = Craft::$app;
        if (!$application instanceof SessionApplication) {
            throw new RuntimeException('Isolated page components need the session application installed as Craft::$app.');
        }
        $originalView = $application->getView();

        $publishPath = FileHelper::normalizePath(sys_get_temp_dir()) . '/campaign-manager-test-assets-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($publishPath);
        $this->isolatedPublishPath = $publishPath;

        try {
            $assetManager = Craft::createObject(['basePath' => $publishPath] + App::assetManagerConfig());
            if (!$assetManager instanceof AssetManager) {
                throw new RuntimeException('The isolated asset manager is not a Craft asset manager.');
            }

            $view = clone $originalView;
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $view->setAssetManager($assetManager);
            $view->clear();
            // clear() empties Yii's public registration arrays only; Craft keeps the bundles and URL-registered scripts it has already seen in sets of its own, which the clone inherited
            $view->setRegisteredAssetBundles([]);
            $view->setRegisteredJsFiles([]);
            $view->setTwig($view->createTwig());
            $view->getTwig()->setCache(false);

            return $application->withLocalComponents(['view' => $view, 'assetManager' => $assetManager], fn() => $work($view));
        } finally {
            $this->removeIsolatedPublishPath();
        }
    }

    /**
     * Remove the owned published-assets directory if one is still recorded;
     * the fallback for teardown, as the directory is normally removed before
     * {@see withIsolatedPageComponents()} returns.
     */
    protected function removeIsolatedPublishPath(): void
    {
        $path = $this->isolatedPublishPath;
        if ($path === null) {
            return;
        }

        $this->isolatedPublishPath = null;
        FileHelper::removeDirectory($path);
        if (is_dir($path)) {
            throw new RuntimeException("The owned published-assets directory {$path} could not be removed.");
        }
    }
}
