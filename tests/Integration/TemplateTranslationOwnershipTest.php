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
use craft\web\View;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every string Campaign Manager renders is translated through its own
 * catalogue, including the labels its JavaScript builds after a queue
 * request, so a control panel in another language never falls back to
 * Craft's catalogue or to English.
 *
 * @since 5.16.0
 */
final class TemplateTranslationOwnershipTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;

    private ?string $originalLanguage = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            $this->restoreLanguage(...),
            $this->removeIsolatedPublishPath(...),
            $this->dropImportSession(...),
            parent::cleanupExternalState(...),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queueLinkTemplates(): iterable
    {
        yield 'campaign index' => ['campaigns/index.twig'];
        yield 'campaign edit' => ['campaigns/edit.twig'];
        yield 'recipient list' => ['recipients/list.twig'];
    }

    #[DataProvider('queueLinkTemplates')]
    public function testTheQueueLinkLabelIsTranslatedByThePlugin(string $template): void
    {
        $contents = $this->template($template);

        self::assertStringContainsString("Craft.t('campaign-manager', 'View')", $contents);
        self::assertStringNotContainsString("Craft.t('app'", $contents);
        self::assertStringContainsString("registerTranslations('campaign-manager', ['View'])", $contents, 'The JavaScript label is only translated when the page registers it.');
    }

    public function testTheRenderedRecipientListRegistersTheGermanQueueLinkLabelForJavaScript(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageRecipients']);
        $this->request('GET', ['site' => $this->siteHandle($siteA)], [], false);
        $page = $this->withSession(fn() => $this->recipientsController()->actionIndex((int)$campaign->id))->data;
        self::assertSame('campaign-manager/recipients/list', $page['template']);

        $this->originalLanguage = Craft::$app->language;
        Craft::$app->language = 'de';

        $rendered = $this->renderCpPageWithSession($page['template'], $page['variables']);
        $html = $rendered['html'];
        $registered = implode(PHP_EOL, $rendered['js'][View::POS_BEGIN] ?? []);

        self::assertStringContainsString("Craft.t('campaign-manager', 'View')", $html, 'The page builds the queue link label through the plugin catalogue.');
        self::assertStringNotContainsString("Craft.t('app'", $html);
        // Craft's layout registers its own "app" labels for the control panel scripts; the plugin's label is registered under its own category.
        self::assertStringContainsString('Craft.translations["campaign-manager"]["View"] = "Anzeigen";', $registered, 'The German label is registered for Craft.t() before the page scripts run.');
        self::assertSame('Anzeigen', Craft::t('campaign-manager', 'View'));
    }

    public function testNoPluginSourceTranslatesThroughCraftsCatalogue(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            $contents = (string)file_get_contents($file);
            if (preg_match("/Craft(::|\\.)t\\(\\s*['\"]app['\"]\\s*,/", $contents) === 1 || preg_match("/\\|\\s*t\\(\\s*['\"]app['\"]\\s*[,)]/", $contents) === 1) {
                $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1);
            }
        }

        self::assertSame([], $offenders);
    }

    public function testTheViewLabelIsPartOfThePluginCatalogue(): void
    {
        $catalogue = require dirname(__DIR__, 2) . '/src/translations/en/campaign-manager.php';

        self::assertArrayHasKey('View', $catalogue);
        self::assertSame('View', Craft::t('campaign-manager', 'View'));
    }

    private function restoreLanguage(): void
    {
        if ($this->originalLanguage !== null) {
            Craft::$app->language = $this->originalLanguage;
            $this->originalLanguage = null;
        }
    }

    private function template(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/' . $path);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'twig', 'js'], true) && !str_contains($file->getPathname(), '/translations/')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
