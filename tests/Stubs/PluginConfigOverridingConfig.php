<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\config\BaseConfig;
use craft\services\Config;

/**
 * Craft config service that adds settings to what a plugin's config file
 * provides, as if the file contained them, or leaves settings out of it, as
 * if the file did not contain them. A test uses it to pin a plugin setting
 * that the project's own config file would otherwise override on every
 * read, or to make such a setting editable again.
 *
 * @since 5.16.0
 */
final class PluginConfigOverridingConfig extends Config
{
    /**
     * @var array<string, array<string, mixed>> Config file name => settings added to that file's values
     */
    private array $fileOverrides = [];

    /**
     * @var array<string, list<string>> Config file name => settings left out of that file's values
     */
    private array $fileRemovals = [];

    /**
     * @param array<string, array<string, mixed>> $fileOverrides
     * @param array<string, list<string>> $fileRemovals
     */
    public static function wrapping(Config $original, array $fileOverrides, array $fileRemovals = []): self
    {
        $config = new self([
            'appType' => $original->appType,
            'env' => $original->env,
            'configDir' => $original->configDir,
            'appDefaultsDir' => $original->appDefaultsDir,
        ]);
        $config->fileOverrides = $fileOverrides;
        $config->fileRemovals = $fileRemovals;

        return $config;
    }

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        $config = parent::getConfigFromFile($filename);

        if (!is_array($config)) {
            return $config;
        }

        foreach ($this->fileRemovals[$filename] ?? [] as $key) {
            unset($config[$key]);
        }

        return array_merge($config, $this->fileOverrides[$filename] ?? []);
    }
}
