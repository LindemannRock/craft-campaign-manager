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
use craft\web\View;
use lindemannrock\base\helpers\CsvImportHelper;
use lindemannrock\campaignmanager\tests\Stubs\InMemorySession;
use lindemannrock\campaignmanager\tests\Stubs\OwnedCsvUpload;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use RuntimeException;

/**
 * The session the recipient import carries its data in between steps, for
 * tests that run the import actions.
 *
 * The import's session data is held by an in-memory session that the
 * console application hands out only while a controller action runs. The
 * using class creates the session in `setUp()` with {@see installImportSession()}
 * and drops it in `cleanupExternalState()` with {@see dropImportSession()}.
 *
 * A control panel page is rendered with the session through
 * {@see renderCpPageWithSession()}, which also keeps the page's View,
 * AssetManager and published assets to the test ({@see IsolatedPageRendering}).
 *
 * @since 5.16.0
 */
trait ImportSessionSupport
{
    use IsolatedPageRendering;

    private ?InMemorySession $importSession = null;
    private ?SessionApplication $lastSessionApplication = null;

    protected function installImportSession(): void
    {
        $this->importSession = new InMemorySession();
    }

    protected function dropImportSession(): void
    {
        $this->importSession = null;
        $this->lastSessionApplication = null;
    }

    /**
     * The session application the most recent {@see withSession()} call
     * installed, for asserting what it still holds once it is no longer
     * installed.
     */
    protected function lastSessionApplication(): SessionApplication
    {
        if ($this->lastSessionApplication === null) {
            throw new RuntimeException('No session application has been installed.');
        }

        return $this->lastSessionApplication;
    }

    protected function session(): InMemorySession
    {
        if ($this->importSession === null) {
            throw new RuntimeException('No import session is installed.');
        }

        return $this->importSession;
    }

    /**
     * Run a controller action with the in-memory session available as the
     * application session, then put the real application back.
     */
    protected function withSession(callable $action): mixed
    {
        $application = Craft::$app;
        if (!$application instanceof \craft\console\Application) {
            throw new RuntimeException('The test runtime is expected to be a console application.');
        }
        $proxy = new SessionApplication($application, $this->session());
        $this->lastSessionApplication = $proxy;
        Craft::$app = $proxy;
        \Yii::$app = $proxy;

        try {
            return $action();
        } finally {
            Craft::$app = $application;
            \Yii::$app = $application;
        }
    }

    /**
     * Render a control panel page with the in-memory session available to
     * PHP and to Twig's `craft.app`, through a page View and AssetManager
     * of this test's own.
     *
     * The real application is captured before the session proxy is
     * installed, and the Twig environment the page renders through is
     * created afterwards, on the isolated View, so it binds `craft.app` to
     * the proxy and is discarded with that View. The application's own View
     * never creates or rebinds its Twig environment under the proxy, so its
     * `craft.app` remains the real application. The isolated View and
     * AssetManager live in the proxy only; the real application's service
     * locator is not written.
     *
     * @param array<string, mixed> $variables
     * @return array{html: string, js: array<int, array<string, string>>} The rendered page and the JavaScript the page registered, by position
     */
    protected function renderCpPageWithSession(string $template, array $variables): array
    {
        return $this->withSession(fn() => $this->withIsolatedPageComponents(function(View $view) use ($template, $variables): array {
            $html = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);

            return ['html' => $html, 'js' => $view->js];
        }));
    }

    /**
     * Render a control panel page as {@see renderCpPageWithSession()} does
     * and return its HTML.
     *
     * @param array<string, mixed> $variables
     */
    protected function renderCpTemplateWithSession(string $template, array $variables): string
    {
        return $this->renderCpPageWithSession($template, $variables)['html'];
    }

    /**
     * Parse a CSV the way the upload step does, from a file the test owns.
     *
     * @return array{headers: list<string>, allRows: list<list<string>>, rowCount: int}
     */
    protected function parseOwnedCsv(string $contents): array
    {
        $path = $this->createTrackedTempDirectory('cm_import_') . '/recipients.csv';
        file_put_contents($path, $contents);

        return CsvImportHelper::parseUpload(OwnedCsvUpload::fromPath($path), [
            'maxRows' => CsvImportHelper::DEFAULT_MAX_ROWS,
            'maxBytes' => CsvImportHelper::DEFAULT_MAX_BYTES,
            'delimiter' => null,
            'detectDelimiter' => true,
        ]);
    }
}
