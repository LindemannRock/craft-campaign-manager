<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\tests\TestCase;

/**
 * @since 5.16.0
 */
final class TemplateEscapingTest extends TestCase
{
    /**
     * @dataProvider providerCountryTemplates
     */
    public function testProviderCountryInfoBoxesEscapeDynamicNames(string $templatePath): void
    {
        $contents = (string)file_get_contents($templatePath);

        self::assertStringContainsString("(previewProvider.name ?? '')|e", $contents);
        self::assertStringContainsString('escapeHtml(providerNames[providerHandle] || providerHandle)', $contents);
        self::assertStringContainsString('function escapeHtml(value)', $contents);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providerCountryTemplates(): array
    {
        $templateRoot = dirname(__DIR__, 2) . '/src/templates';

        return [
            'settings provider countries' => [$templateRoot . '/settings/general.twig'],
            'campaign provider countries' => [$templateRoot . '/campaigns/edit.twig'],
        ];
    }
}
