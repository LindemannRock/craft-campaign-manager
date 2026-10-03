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
 * provides, as if the file contained them. A test uses it to pin a plugin
 * setting that the project's own config file would otherwise override on
 * every read.
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
     * @param array<string, array<string, mixed>> $fileOverrides
     */
    public static function wrapping(Config $original, array $fileOverrides): self
    {
        $config = new self([
            'appType' => $original->appType,
            'env' => $original->env,
            'configDir' => $original->configDir,
            'appDefaultsDir' => $original->appDefaultsDir,
        ]);
        $config->fileOverrides = $fileOverrides;

        return $config;
    }

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        $config = parent::getConfigFromFile($filename);

        if (!isset($this->fileOverrides[$filename]) || !is_array($config)) {
            return $config;
        }

        return array_merge($config, $this->fileOverrides[$filename]);
    }
}
