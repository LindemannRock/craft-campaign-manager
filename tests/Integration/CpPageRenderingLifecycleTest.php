<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Closure;
use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\web\AssetManager;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use DomainException;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\tests\Stubs\SessionApplication;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Twig\Environment;
use Twig\Error\LoaderError;

/**
 * A test that renders a control panel page leaves the application exactly as
 * it found it: the same application, View and AssetManager objects are
 * loaded afterwards under their original component definitions with their
 * state unchanged, the View's Twig environment still binds `craft.app` to
 * the real application, nothing the page published is cached or left on
 * disk, no component override lingers in the session application, and this
 * holds whether the render returned or threw.
 */
final class CpPageRenderingLifecycleTest extends SiteRestrictedUserTestCase
{
    use ImportSessionSupport;

    /** A script a page registers by URL rather than through an asset bundle; Craft records those in a registration set of their own. */
    private const PROBE_SCRIPT_URL = '/campaign-manager-test-probe.js';

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
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

    public function testRenderingAPageLeavesTheApplicationItsViewAndItsAssetManagerAsTheyWere(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageSettings']);
        $before = $this->captureState();
        $publishPath = null;
        $publishedDuringRender = [];

        // Defined before the arrow function below, which would otherwise hand the closure copies of the captured variables
        $render = function(View $view) use (&$publishPath, &$publishedDuringRender, $before): array {
            self::assertSame($view, Craft::$app->getView(), 'The page renders through the isolated View.');
            self::assertNotSame($before['view'], $view);
            self::assertSame($view->getAssetManager(), Craft::$app->getAssetManager(), 'The page publishes through the isolated AssetManager.');
            self::assertNotSame($before['assetManager'], $view->getAssetManager());
            $publishPath = (string)$view->getAssetManager()->basePath;

            $html = $view->renderTemplate('campaign-manager/settings/general', ['settings' => CampaignManager::$plugin->getSettings(), 'readOnly' => true], View::TEMPLATE_MODE_CP);
            $publishedDuringRender = FileHelper::findFiles($publishPath);

            return ['html' => $html, 'js' => $view->js];
        };
        $rendered = $this->withSession(fn() => $this->withIsolatedPageComponents($render));

        self::assertStringContainsString('defaultSenderIdHandle', $rendered['html'], 'The real settings page rendered.');
        self::assertNotSame([], $rendered['js'], 'The page registered JavaScript on the isolated View.');
        self::assertNotSame([], $publishedDuringRender, 'The page published its asset bundles into the owned directory.');
        self::assertIsString($publishPath);
        self::assertDirectoryDoesNotExist($publishPath, 'The owned published-assets directory is removed.');
        $this->assertStateRestored($before);
    }

    public function testAFailedRenderLeavesTheApplicationItsViewAndItsAssetManagerAsTheyWere(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageSettings']);
        $before = $this->captureState();
        $missingTemplate = 'campaign-manager/' . strtolower($this->nextTestMarker('cm_test_missing_', 'template'));

        try {
            $this->renderCpTemplateWithSession($missingTemplate, []);
            self::fail('Rendering a template that does not exist was expected to throw.');
        } catch (LoaderError $e) {
            self::assertStringContainsString($missingTemplate, $e->getMessage());
        }

        self::assertSame([], glob(FileHelper::normalizePath(sys_get_temp_dir()) . '/campaign-manager-test-assets-*') ?: [], 'No owned published-assets directory remains.');
        $this->assertStateRestored($before);
    }

    public function testAssetsPublishedDuringARenderLiveOnlyInTheOwnedDirectoryEvenWhenTheRenderThrows(): void
    {
        $source = $this->createTrackedTempDirectory('cm_asset_source_');
        file_put_contents($source . '/probe.js', 'window.probe = true;');
        $before = $this->captureState();
        // Yii derives an unpublished source's path from its hash, under the application's own base path
        $projectPath = (string)$before['assetManager']->getPublishedPath($source);
        self::assertStringStartsWith((string)$before['assetManager']->basePath, $projectPath);
        self::assertDirectoryDoesNotExist($projectPath, 'The source has never been published through the application.');
        $publishedPath = null;
        $publishPath = null;

        $render = function(View $view) use ($source, &$publishedPath, &$publishPath): void {
            $publishPath = (string)$view->getAssetManager()->basePath;
            [$publishedPath] = Craft::$app->getAssetManager()->publish($source);
            self::assertStringStartsWith($publishPath . '/', (string)$publishedPath, 'The source is published into the owned directory.');
            self::assertFileExists($publishedPath . '/probe.js');

            throw new RuntimeException('The render failed after publishing.');
        };
        try {
            $this->withSession(fn() => $this->withIsolatedPageComponents($render));
            self::fail('The failure was expected to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('The render failed after publishing.', $e->getMessage());
        }

        self::assertIsString($publishedPath);
        self::assertIsString($publishPath);
        self::assertDirectoryDoesNotExist($publishedPath);
        self::assertDirectoryDoesNotExist($publishPath);
        self::assertSame($projectPath, Craft::$app->getAssetManager()->getPublishedPath($source), 'The application AssetManager still derives the source path from its hash; it holds no path into the removed directory.');
        self::assertDirectoryDoesNotExist($projectPath, 'Nothing was published into the project either.');
        $this->assertStateRestored($before);
    }

    public function testAPageRegistersAndPublishesEverythingItNeedsWhateverTheApplicationViewHadAlreadyRegistered(): void
    {
        [$siteA] = $this->siteIds(1);
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:manageSettings']);
        $before = $this->captureState();
        $variables = ['settings' => CampaignManager::$plugin->getSettings(), 'readOnly' => true];

        $clean = $this->renderSettingsPageThroughIsolatedView($variables);
        self::assertTrue($clean['blankAtStart'], 'The isolated View starts with nothing registered, publicly or in its Craft registration sets.');
        self::assertStringContainsString('defaultSenderIdHandle', $clean['html']);
        self::assertNotSame([], $clean['bundles'], 'The page registers asset bundles.');
        self::assertNotSame([], $clean['jsFiles'], 'The script registered by URL is recorded in the Craft registration set.');
        self::assertContains(self::PROBE_SCRIPT_URL, $clean['jsFileTags'], 'The script registered by URL is emitted.');
        self::assertNotSame([], $clean['published'], 'The page publishes bundles into the owned directory.');

        // An application View that has already registered every bundle this page needs and the script it registers by URL, as one that rendered the page earlier in the process would have
        $seededView = clone $before['view'];
        $seededView->setRegisteredAssetBundles($clean['bundles']);
        $seededView->setRegisteredJsFiles([]);
        $seededView->registerJsFile(self::PROBE_SCRIPT_URL);
        self::assertSame($clean['jsFiles'], self::registeredSets($seededView)['jsFiles'], 'The seeded View holds the same JavaScript-file registration the page produces.');
        $seeded = $this->renderSettingsPageThroughIsolatedView($variables, $seededView);

        self::assertTrue($seeded['blankAtStart'], 'The isolated View starts with nothing registered even when the application View had registered it all.');
        self::assertStringContainsString('defaultSenderIdHandle', $seeded['html']);
        self::assertSame($clean['bundles'], $seeded['bundles'], 'The page registers the same asset bundles.');
        self::assertSame($clean['jsFiles'], $seeded['jsFiles'], 'The script registered by URL is recorded again.');
        self::assertSame($clean['jsFileTags'], $seeded['jsFileTags'], 'The page emits the same JavaScript file tags, including the script registered by URL.');
        self::assertSame($clean['published'], $seeded['published'], 'The page publishes the same bundles.');
        $this->assertStateRestored($before);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function renderOutcomes(): array
    {
        return ['the render returns' => [false], 'the render throws' => [true]];
    }

    #[DataProvider('renderOutcomes')]
    public function testAnApplicationViewWithoutATwigEnvironmentKeepsNoneUntilAfterTheRenderAndThenBindsTheRealApplication(bool $renderThrows): void
    {
        $real = Craft::$app;
        $before = $this->captureState();
        $freshView = Craft::createObject(App::viewConfig());
        self::assertInstanceOf(View::class, $freshView);
        self::assertNull(self::cpTwigEnvironment($freshView), 'A newly created View has no control panel Twig environment yet.');
        $boundDuringRender = null;
        $uncreatedDuringRender = null;

        // Defined before the arrow functions below, which would otherwise hand the closure copies of the captured variables
        $render = function(View $view) use ($freshView, $renderThrows, &$boundDuringRender, &$uncreatedDuringRender): string {
            self::assertNotSame($freshView, $view, 'The page renders through a View of its own, not the application View.');
            $html = $view->renderTemplate('_includes/forms/text', ['name' => 'probe'], View::TEMPLATE_MODE_CP);
            $craft = $view->getTwig(View::TEMPLATE_MODE_CP)->getGlobals()['craft'] ?? null;
            self::assertInstanceOf(CraftVariable::class, $craft);
            $boundDuringRender = $craft->app;
            $uncreatedDuringRender = self::cpTwigEnvironment($freshView) === null;
            if ($renderThrows) {
                // Not a RuntimeException, which a failed assertion inside the render also is
                throw new DomainException('The render failed.');
            }

            return $html;
        };
        $installFreshView = function() use ($freshView, $render): string {
            $proxy = Craft::$app;
            self::assertInstanceOf(SessionApplication::class, $proxy);

            return $proxy->withLocalComponents(['view' => $freshView], function() use ($freshView, $render): string {
                self::assertSame($freshView, Craft::$app->getView(), 'The fresh View answers as the application View.');
                self::assertNull(self::cpTwigEnvironment($freshView), 'Installing the View creates no environment.');

                return $this->withIsolatedPageComponents($render);
            });
        };

        try {
            $rendered = $this->withSession($installFreshView);
            self::assertFalse($renderThrows, 'The failure was expected to propagate.');
            self::assertStringContainsString('name="probe"', $rendered);
        } catch (DomainException $e) {
            self::assertTrue($renderThrows, 'The render was expected to return.');
            self::assertSame('The render failed.', $e->getMessage());
        }

        self::assertInstanceOf(SessionApplication::class, $boundDuringRender, 'The environment created for the render belongs to the isolated View and binds craft.app to the session proxy.');
        self::assertTrue($uncreatedDuringRender, 'The application View had no control panel Twig environment while the page rendered.');
        self::assertNull(self::cpTwigEnvironment($freshView), 'The application View still has no control panel Twig environment after the render.');
        self::assertSame($real, Craft::$app);
        $this->assertStateRestored($before);
        self::assertNotSame($freshView, Craft::$app->getView(), 'The fresh View was never installed in the real application.');

        $twig = $freshView->getTwig(View::TEMPLATE_MODE_CP);
        $twig->getFilters();
        $craft = $twig->getGlobals()['craft'] ?? null;
        self::assertInstanceOf(CraftVariable::class, $craft);
        self::assertSame($real, $craft->app, 'The first environment the application View creates after the render binds craft.app to the real application, not to the proxy.');
    }

    /**
     * @return array{application: object, view: View, assetManager: AssetManager, viewDefinition: mixed, assetManagerDefinition: mixed, twig: \Twig\Environment, craft: CraftVariable, viewDigest: string, assetManagerDigest: string, registrations: array<string, mixed>, twigCache: mixed, templateMode: string}
     */
    private function captureState(): array
    {
        $application = Craft::$app;
        $view = $application->getView();
        $assetManager = $application->getAssetManager();
        $twig = $view->getTwig(View::TEMPLATE_MODE_CP);
        // Twig resolves the globals once the extensions are initialised, as any render does; until then every lookup builds a new craft variable
        $twig->getFilters();
        $craft = $twig->getGlobals()['craft'] ?? null;
        self::assertInstanceOf(CraftVariable::class, $craft);
        self::assertSame($application, $craft->app, 'Before the render, craft.app is the real application.');

        return [
            'application' => $application,
            'view' => $view,
            'assetManager' => $assetManager,
            'viewDefinition' => $application->getComponents(true)['view'],
            'assetManagerDefinition' => $application->getComponents(true)['assetManager'],
            'twig' => $twig,
            'craft' => $craft,
            'viewDigest' => self::stateDigest($view),
            'assetManagerDigest' => self::stateDigest($assetManager),
            'registrations' => self::registrations($view),
            'twigCache' => $twig->getCache(),
            'templateMode' => $view->getTemplateMode(),
        ];
    }

    /**
     * @param array{application: object, view: View, assetManager: AssetManager, viewDefinition: mixed, assetManagerDefinition: mixed, twig: \Twig\Environment, craft: CraftVariable, viewDigest: string, assetManagerDigest: string, registrations: array<string, mixed>, twigCache: mixed, templateMode: string} $before
     */
    private function assertStateRestored(array $before): void
    {
        self::assertSame($before['application'], Craft::$app, 'The real application is installed again.');
        self::assertSame($before['application'], \Yii::$app);
        self::assertSame($before['view'], Craft::$app->getView(), 'The application View object is the same.');
        self::assertSame($before['assetManager'], Craft::$app->getAssetManager(), 'The application AssetManager object is the same.');
        self::assertSame($before['viewDefinition'], Craft::$app->getComponents(true)['view'], 'The view component definition is as it was.');
        self::assertSame($before['assetManagerDefinition'], Craft::$app->getComponents(true)['assetManager'], 'The assetManager component definition is as it was.');
        self::assertSame($before['view'], Craft::$app->getComponents(false)['view'], 'The same View object remains loaded.');
        self::assertSame($before['assetManager'], Craft::$app->getComponents(false)['assetManager'], 'The same AssetManager object remains loaded.');
        self::assertSame([], $this->lastSessionApplication()->getLocalComponentIds(), 'No component override remains in the session application.');
        self::assertSame($before['twig'], $before['view']->getTwig(View::TEMPLATE_MODE_CP), 'The View keeps its Twig environment.');
        self::assertSame($before['craft'], $before['twig']->getGlobals()['craft'] ?? null);
        self::assertSame($before['application'], $before['craft']->app, 'craft.app still binds the real application.');
        self::assertSame($before['twigCache'], $before['twig']->getCache());
        self::assertSame($before['templateMode'], $before['view']->getTemplateMode());
        self::assertSame($before['registrations'], self::registrations($before['view']), 'The View registered nothing during the render.');
        self::assertSame($before['viewDigest'], self::stateDigest($before['view']), 'Every View property, public and private, is as it was.');
        self::assertSame($before['assetManagerDigest'], self::stateDigest($before['assetManager']), 'Every AssetManager property, including its published-path cache, is as it was.');
    }

    /**
     * Render the settings page through the isolation helper, from the View
     * the application answers with (or from the given one, placed in front
     * of it in the session application), register one script by URL as a
     * page may, and report what the isolated View started with and what
     * was registered and published.
     *
     * @param array<string, mixed> $variables
     * @return array{blankAtStart: bool, html: string, bundles: list<string>, jsFiles: list<string>, jsFileTags: list<string>, published: list<string>}
     */
    private function renderSettingsPageThroughIsolatedView(array $variables, ?View $applicationView = null): array
    {
        $result = null;
        // Defined before the arrow functions below, which would otherwise hand the closure copies of the captured variables
        $render = function(View $view) use ($variables, &$result): void {
            $registrations = self::registrations($view);
            unset($registrations['title']);
            $blankAtStart = $registrations === ['js' => [], 'jsFiles' => [], 'css' => [], 'cssFiles' => [], 'metaTags' => [], 'linkTags' => [], 'assetBundles' => []]
                && self::registeredSets($view) === ['bundles' => [], 'jsFiles' => []];

            $html = $view->renderTemplate('campaign-manager/settings/general', $variables, View::TEMPLATE_MODE_CP);
            $view->registerJsFile(self::PROBE_SCRIPT_URL);

            $published = array_values(array_diff(scandir((string)$view->getAssetManager()->basePath) ?: [], ['.', '..']));
            sort($published);
            // Each publication stamps its copies afresh, so the version query Craft appends is dropped before tags are compared across renders
            $jsFileTags = array_map(static fn(string $key): string => preg_replace('/\?v=\d+$/', '', $key) ?? $key, array_merge([], ...array_map('array_keys', $view->jsFiles)));
            $result = ['blankAtStart' => $blankAtStart, 'html' => $html, 'jsFileTags' => $jsFileTags, 'published' => $published] + self::registeredSets($view);
        };
        $work = fn() => $this->withIsolatedPageComponents($render);
        $this->withSession($applicationView === null
            ? $work
            : fn() => $this->lastSessionApplication()->withLocalComponents(['view' => $applicationView], $work));
        self::assertIsArray($result);

        return $result;
    }

    /**
     * The hashes Craft's View holds for the asset bundles and JavaScript
     * files it has already registered; read, as no accessor exists.
     *
     * @return array{bundles: list<string>, jsFiles: list<string>}
     */
    private static function registeredSets(View $view): array
    {
        $keys = static fn(string $property): array => array_map('strval', array_keys((new ReflectionProperty(View::class, $property))->getValue($view)));

        return ['bundles' => $keys('_registeredAssetBundles'), 'jsFiles' => $keys('_registeredJsFiles')];
    }

    /**
     * The View's control panel Twig environment if it has created one; read
     * without creating it, which {@see View::getTwig()} would do.
     */
    private static function cpTwigEnvironment(View $view): ?Environment
    {
        $environment = (new ReflectionProperty(View::class, '_cpTwig'))->getValue($view);

        return $environment instanceof Environment ? $environment : null;
    }

    /**
     * The View's public registration state.
     *
     * @return array<string, mixed>
     */
    private static function registrations(View $view): array
    {
        return [
            'js' => $view->js,
            'jsFiles' => $view->jsFiles,
            'css' => $view->css,
            'cssFiles' => $view->cssFiles,
            'metaTags' => $view->metaTags,
            'linkTags' => $view->linkTags,
            'assetBundles' => array_keys($view->assetBundles),
            'title' => $view->title,
        ];
    }

    /**
     * A digest of every property the object holds, including private ones
     * declared by its parents. Scalars and arrays are taken by value; nested
     * objects and closures by identity, so a replaced or added object shows
     * up as well as a changed value.
     */
    private static function stateDigest(object $object): string
    {
        $properties = [];
        for ($class = new ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $properties[$class->getName() . '::' . $property->getName()] = $property->isInitialized($object)
                    ? self::byValueOrIdentity($property->getValue($object))
                    : '(uninitialized)';
            }
        }
        ksort($properties);

        return hash('sha256', serialize($properties));
    }

    private static function byValueOrIdentity(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::byValueOrIdentity($item);
            }

            return $normalized;
        }
        if ($value instanceof Closure) {
            return 'closure#' . spl_object_id($value);
        }
        if (is_object($value)) {
            return $value::class . '#' . spl_object_id($value);
        }
        if (is_resource($value)) {
            return 'resource#' . (int)$value;
        }

        return $value;
    }
}
