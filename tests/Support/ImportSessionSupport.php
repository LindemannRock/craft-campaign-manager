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
 * @since 5.16.0
 */
trait ImportSessionSupport
{
    private ?InMemorySession $importSession = null;

    protected function installImportSession(): void
    {
        $this->importSession = new InMemorySession();
    }

    protected function dropImportSession(): void
    {
        $this->importSession = null;
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
