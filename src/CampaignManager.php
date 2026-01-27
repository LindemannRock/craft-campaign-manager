<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * Campaign management with SMS, email, and WhatsApp invitations
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\db\ElementQuery;
use craft\events\ConfigEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\behaviors\CampaignBehavior;
use lindemannrock\campaignmanager\behaviors\CampaignQueryBehavior;
use lindemannrock\campaignmanager\behaviors\FormBehavior;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\integrations\SmsManagerIntegration;
use lindemannrock\campaignmanager\models\Settings;
use lindemannrock\campaignmanager\services\CampaignsService;
use lindemannrock\campaignmanager\services\CustomersService;
use lindemannrock\campaignmanager\services\EmailsService;
use lindemannrock\campaignmanager\services\SmsService;
use lindemannrock\campaignmanager\variables\CampaignManagerVariable;
use lindemannrock\campaignmanager\web\twig\Extension;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\events\RegisterIntegrationsEvent as SmsManagerRegisterIntegrationsEvent;
use lindemannrock\smsmanager\services\IntegrationsService as SmsManagerIntegrationsService;
use verbb\formie\elements\Form;
use verbb\formie\events\SubmissionEvent;
use verbb\formie\services\Submissions;
use yii\base\Event;

/**
 * Campaign Manager Plugin
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 *
 * @property-read CampaignsService $campaigns
 * @property-read CustomersService $customers
 * @property-read EmailsService $emails
 * @property-read SmsService $sms
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class CampaignManager extends Plugin
{
    use LoggingTrait;

    /**
     * @var CampaignManager|null Singleton plugin instance
     */
    public static ?CampaignManager $plugin = null;

    /**
     * @var string Plugin schema version for migrations
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool Whether the plugin exposes a control panel settings page
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin registers a control panel section
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Set alias for resources
        Craft::setAlias('@lindemannrock/campaignmanager', __DIR__);

        // Bootstrap base module (logging + Twig extension)
        PluginHelper::bootstrap(
            $this,
            'campaignManagerHelper',
            ['campaignManager:viewLogs'],
            ['campaignManager:downloadLogs']
        );
        PluginHelper::applyPluginNameFromConfig($this);

        // Set up services
        $this->setComponents([
            'campaigns' => CampaignsService::class,
            'customers' => CustomersService::class,
            'emails' => EmailsService::class,
            'sms' => SmsService::class,
        ]);

        // Set controller namespace based on app type
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lindemannrock\\campaignmanager\\console\\controllers';
        }
        if (Craft::$app instanceof WebApplication) {
            $this->controllerNamespace = 'lindemannrock\\campaignmanager\\controllers';
        }

        // Register translations
        Craft::$app->i18n->translations['campaign-manager'] = [
            'class' => \craft\i18n\PhpMessageSource::class,
            'sourceLanguage' => 'en',
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
            'allowOverrides' => true,
        ];

        // Register project config handlers
        $this->registerProjectConfigEventHandlers();

        // Register event handlers
        $this->registerEventHandlers();
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        // Check permissions
        $hasCampaignsAccess = $user->checkPermission('campaignManager:viewCampaigns');
        $hasSettingsAccess = $user->checkPermission('campaignManager:manageSettings');

        // If no access at all, hide from nav
        if (!$hasCampaignsAccess && !$hasSettingsAccess) {
            return null;
        }

        if ($item) {
            $item['label'] = $this->getSettings()->getFullName();
            $item['icon'] = '@appicons/share.svg';

            $item['subnav'] = [];

            // Campaigns
            if ($hasCampaignsAccess) {
                $item['subnav']['campaigns'] = [
                    'label' => Craft::t('campaign-manager', 'Campaigns'),
                    'url' => 'campaign-manager',
                ];
            }

            // Customers
            if ($user->checkPermission('campaignManager:viewCustomers')) {
                $item['subnav']['customers'] = [
                    'label' => Craft::t('campaign-manager', 'Customers'),
                    'url' => 'campaign-manager/customers',
                ];
            }

            // System Logs (using logging library)
            if (Craft::$app->getPlugins()->isPluginInstalled('logging-library') &&
                Craft::$app->getPlugins()->isPluginEnabled('logging-library')) {
                $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                    'campaignManager:viewLogs',
                ]);
            }

            // Settings
            if ($hasSettingsAccess) {
                $item['subnav']['settings'] = [
                    'label' => Craft::t('campaign-manager', 'Settings'),
                    'url' => 'campaign-manager/settings',
                ];
            }
        }

        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('campaign-manager/settings');
    }

    /**
     * Translation helper
     */
    public static function t(string $message, array $params = [], ?string $language = null): string
    {
        return Craft::t('campaign-manager', $message, $params, $language);
    }

    /**
     * Register project config event handlers for field layouts
     */
    private function registerProjectConfigEventHandlers(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd('campaign-manager.fieldLayouts.{uid}', [$this, 'handleChangedFieldLayout'])
            ->onUpdate('campaign-manager.fieldLayouts.{uid}', [$this, 'handleChangedFieldLayout'])
            ->onRemove('campaign-manager.fieldLayouts.{uid}', [$this, 'handleDeletedFieldLayout']);
    }

    /**
     * Handle field layout changes from project config
     */
    public function handleChangedFieldLayout(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        $fieldLayout = FieldLayout::createFromConfig($data);
        $fieldLayout->uid = $uid;
        $fieldLayout->type = Campaign::class;

        Craft::$app->getFields()->saveLayout($fieldLayout, false);

        $this->logInfo('Applied Campaign Manager field layout from project config', ['uid' => $uid]);
    }

    /**
     * Handle field layout deletion from project config
     */
    public function handleDeletedFieldLayout(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $fieldLayout = Craft::$app->getFields()->getLayoutByUid($uid);

        if ($fieldLayout) {
            Craft::$app->getFields()->deleteLayoutById($fieldLayout->id);
        }
    }

    /**
     * Register all event handlers
     */
    private function registerEventHandlers(): void
    {
        // Register Campaign element type
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Campaign::class;
            }
        );

        // Register behaviors on elements (for backwards compatibility with entry-based campaigns)
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['campaignManager'] = CampaignBehavior::class;
            }
        );

        Event::on(
            ElementQuery::class,
            ElementQuery::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['campaignManagerQuery'] = CampaignQueryBehavior::class;
            }
        );

        Event::on(
            Form::class,
            Form::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['campaignManagerForm'] = FormBehavior::class;
            }
        );

        // Handle form submissions for surveys
        Event::on(
            Submissions::class,
            Submissions::EVENT_AFTER_SUBMISSION,
            function(SubmissionEvent $event) {
                if (!$event->success) {
                    return;
                }
                $submission = $event->submission;
                $invitationCode = Craft::$app->getRequest()->get('code');
                if (empty($invitationCode)) {
                    return;
                }

                self::$plugin->customers->processCampaignSubmission($submission, $invitationCode);
            }
        );

        // Register with SMS Manager's integration system (for usage tracking)
        Event::on(
            SmsManagerIntegrationsService::class,
            SmsManagerIntegrationsService::EVENT_REGISTER_INTEGRATIONS,
            function(SmsManagerRegisterIntegrationsEvent $event) {
                $event->register('campaign-manager', 'Campaign Manager', SmsManagerIntegration::class);
            }
        );

        // Register template roots
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['campaign-manager'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['campaign-manager'] = __DIR__ . '/templates';
            }
        );

        // Register CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, $this->getCpUrlRules());
            }
        );

        // Register site URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['customers/load'] = 'campaign-manager/customers/load';
                $event->rules['customers/delete'] = 'campaign-manager/customers/delete-from-cp';

                $settings = $this->getSettings();
                if (isset($settings->invitationTemplate)) {
                    $event->rules[$settings->invitationRoute] = ['template' => $settings->invitationTemplate];
                }
            }
        );

        // Register Twig variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('campaignManager', CampaignManagerVariable::class);
            }
        );

        // Register permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $settings = $this->getSettings();
                $event->permissions[] = [
                    'heading' => $settings->getDisplayName(),
                    'permissions' => $this->getPluginPermissions(),
                ];
            }
        );

        // Register Twig extension
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Get CP URL rules
     */
    private function getCpUrlRules(): array
    {
        return [
            // Element index (uses Craft's native element index)
            'campaign-manager' => 'campaign-manager/campaigns/index',

            // Campaign edit
            'campaign-manager/campaigns/new' => 'campaign-manager/campaigns/edit',
            'campaign-manager/campaigns/<campaignId:\d+>' => 'campaign-manager/campaigns/edit',

            // Customers (global view)
            'campaign-manager/customers' => 'campaign-manager/customers/global-index',
            'campaign-manager/customers/export' => 'campaign-manager/customers/export-global',

            // Customers (campaign-specific)
            'campaign-manager/campaigns/<campaignId:\d+>/customers' => 'campaign-manager/customers/index',
            'campaign-manager/campaigns/<campaignId:\d+>/add-customer' => 'campaign-manager/customers/add-form',
            'campaign-manager/campaigns/<campaignId:\d+>/import-customers' => 'campaign-manager/customers/import-form',
            'campaign-manager/campaigns/<campaignId:\d+>/map-customers' => 'campaign-manager/customers/map',
            'campaign-manager/campaigns/<campaignId:\d+>/export-customers' => 'campaign-manager/customers/export-customers',
            'campaign-manager/customers/load' => 'campaign-manager/customers/load',

            // Settings
            'campaign-manager/settings' => 'campaign-manager/settings/index',
            'campaign-manager/settings/field-layout' => 'campaign-manager/settings/field-layout',
        ];
    }

    /**
     * Get plugin permissions
     */
    private function getPluginPermissions(): array
    {
        return [
            'campaignManager:manageCampaigns' => [
                'label' => Craft::t('campaign-manager', 'Manage campaigns'),
                'nested' => [
                    'campaignManager:viewCampaigns' => [
                        'label' => Craft::t('campaign-manager', 'View campaigns'),
                    ],
                    'campaignManager:createCampaigns' => [
                        'label' => Craft::t('campaign-manager', 'Create campaigns'),
                    ],
                    'campaignManager:editCampaigns' => [
                        'label' => Craft::t('campaign-manager', 'Edit campaigns'),
                    ],
                    'campaignManager:deleteCampaigns' => [
                        'label' => Craft::t('campaign-manager', 'Delete campaigns'),
                    ],
                ],
            ],
            'campaignManager:manageCustomers' => [
                'label' => Craft::t('campaign-manager', 'Manage customers'),
                'nested' => [
                    'campaignManager:viewCustomers' => [
                        'label' => Craft::t('campaign-manager', 'View customers'),
                    ],
                    'campaignManager:importCustomers' => [
                        'label' => Craft::t('campaign-manager', 'Import customers'),
                    ],
                    'campaignManager:deleteCustomers' => [
                        'label' => Craft::t('campaign-manager', 'Delete customers'),
                    ],
                ],
            ],
            'campaignManager:viewLogs' => [
                'label' => Craft::t('campaign-manager', 'View logs'),
                'nested' => [
                    'campaignManager:downloadLogs' => [
                        'label' => Craft::t('campaign-manager', 'Download logs'),
                    ],
                ],
            ],
            'campaignManager:manageSettings' => [
                'label' => Craft::t('campaign-manager', 'Manage settings'),
            ],
        ];
    }
}
