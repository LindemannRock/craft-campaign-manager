<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\models\FieldLayout;
use craft\web\Controller;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\models\Settings;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class SettingsController extends Controller
{
    use LoggingTrait;

    /**
     * @var bool Whether admin changes are allowed
     */
    private bool $readOnly = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
        $this->readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('campaignManager:manageSettings');

        // Only field layouts respect allowAdminChanges (stored in project config)
        // Regular settings are stored in DB and should always be editable
        if ($action->id === 'save-field-layout') {
            if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
            }
        }

        return parent::beforeAction($action);
    }

    /**
     * Settings index
     */
    public function actionIndex(): Response
    {
        $settings = CampaignManager::$plugin->getSettings();

        return $this->renderTemplate('campaign-manager/settings/general', [
            'settings' => $settings,
            'readOnly' => $this->readOnly,
        ]);
    }

    /**
     * Save settings
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        // Load settings from database (not project config)
        $settings = Settings::loadFromDatabase();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $section = $this->_validSettingsSection(
            Craft::$app->getRequest()->getBodyParam('section', 'general'),
        );

        // Fields that should be cast to int (nullable)
        $nullableIntFields = ['defaultSenderIdId'];

        // Fields that should be nullable strings (empty string = null)
        $nullableStringFields = ['defaultProviderHandle', 'defaultSenderIdHandle'];

        // Update settings with posted values
        foreach ($postedSettings as $key => $value) {
            if (property_exists($settings, $key) && !$settings->isOverriddenByConfig($key)) {
                if (in_array($key, $nullableIntFields, true)) {
                    $settings->$key = $value !== '' && $value !== null ? (int)$value : null;
                } elseif (in_array($key, $nullableStringFields, true)) {
                    $settings->$key = $value !== '' && $value !== null ? $value : null;
                } else {
                    $settings->$key = $value;
                }
            }
        }

        $attributesToValidate = $this->_validationAttributesForSection($section);
        $attributesToValidate = array_values(array_filter(
            $attributesToValidate,
            fn(string $attribute): bool => !$settings->isOverriddenByConfig($attribute),
        ));

        // Validate
        if (!$settings->validate($attributesToValidate)) {
            $this->logError('Settings validation failed', ['errors' => $settings->getErrors()]);

            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate("campaign-manager/settings/{$section}", [
                'settings' => $settings,
                'readOnly' => $this->readOnly,
            ]);
        }

        // Save to database (works even when allowAdminChanges is false)
        if (!$settings->saveToDatabase($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate("campaign-manager/settings/{$section}", [
                'settings' => $settings,
                'readOnly' => $this->readOnly,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Validate and sanitize the settings section parameter.
     */
    private function _validSettingsSection(string $section): string
    {
        $allowed = ['general', 'interface'];
        return in_array($section, $allowed, true) ? $section : 'general';
    }

    /**
     * Get settings attributes that belong to a section.
     *
     * @param string $section
     * @return array<int, string>
     */
    private function _validationAttributesForSection(string $section): array
    {
        return match ($section) {
            'general' => [
                'pluginName',
                'defaultProviderHandle',
                'defaultSenderIdHandle',
                'invitationRoute',
                'invitationTemplate',
                'logLevel',
                'enableActivityLogs',
                'activityLogsRetention',
                'activityLogsLimit',
                'activityAutoTrimLogs',
            ],
            'interface' => [
                'itemsPerPage',
            ],
            default => [],
        };
    }

    /**
     * Interface settings page
     */
    public function actionInterface(): Response
    {
        $settings = CampaignManager::$plugin->getSettings();

        return $this->renderTemplate('campaign-manager/settings/interface', [
            'settings' => $settings,
            'readOnly' => $this->readOnly,
        ]);
    }

    /**
     * Field layout settings page
     */
    public function actionFieldLayout(): Response
    {
        // Get field layout from project config
        $fieldLayouts = Craft::$app->getProjectConfig()->get('campaign-manager.fieldLayouts') ?? [];

        $fieldLayout = null;

        if (!empty($fieldLayouts)) {
            $fieldLayoutUid = array_key_first($fieldLayouts);
            $fieldLayout = Craft::$app->getFields()->getLayoutByUid($fieldLayoutUid);
        }

        // Fallback: try to get by type
        if (!$fieldLayout) {
            $fieldLayout = Craft::$app->getFields()->getLayoutByType(Campaign::class);
        }

        if (!$fieldLayout) {
            // Create a new field layout if none exists
            $fieldLayout = new FieldLayout([
                'type' => Campaign::class,
            ]);

            // Save the empty field layout so it has an ID
            Craft::$app->getFields()->saveLayout($fieldLayout);

            // Save to project config only if not in read-only mode
            if (!$this->readOnly) {
                $fieldLayoutConfig = $fieldLayout->getConfig();
                if ($fieldLayoutConfig) {
                    Craft::$app->getProjectConfig()->set(
                        "campaign-manager.fieldLayouts.{$fieldLayout->uid}",
                        $fieldLayoutConfig,
                        'Create Campaign Manager field layout'
                    );
                }
            }
        }

        return $this->renderTemplate('campaign-manager/settings/field-layout', [
            'fieldLayout' => $fieldLayout,
            'readOnly' => $this->readOnly,
        ]);
    }

    /**
     * Save field layout
     */
    public function actionSaveFieldLayout(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('campaignManager:manageSettings');

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Campaign::class;

        if (!Craft::$app->getFields()->saveLayout($fieldLayout)) {
            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Couldn\'t save field layout.'));
            return null;
        }

        // Save field layout config to project config
        $fieldLayoutConfig = $fieldLayout->getConfig();
        if ($fieldLayoutConfig) {
            Craft::$app->getProjectConfig()->set(
                "campaign-manager.fieldLayouts.{$fieldLayout->uid}",
                $fieldLayoutConfig,
                'Save Campaign Manager field layout'
            );
        }

        $this->logInfo('Field layout saved', ['uid' => $fieldLayout->uid]);

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Field layout saved.'));

        return $this->redirectToPostedUrl();
    }
}
