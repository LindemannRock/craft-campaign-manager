<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\web\UploadedFile;

/**
 * An uploaded CSV backed by a file the test owns.
 *
 * PHP only lets a file that arrived through an HTTP upload be moved as an
 * upload, so saving copies the owned file instead. Everything else about
 * the upload is read exactly as the import reads a real one.
 *
 * @since 5.16.0
 */
final class OwnedCsvUpload extends UploadedFile
{
    public static function fromPath(string $path): self
    {
        return new self([
            'name' => basename($path),
            'tempName' => $path,
            'type' => 'text/csv',
            'size' => (int)filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]);
    }

    public function saveAs($file, $deleteTempFile = true): bool
    {
        return copy($this->tempName, (string)$file);
    }
}
