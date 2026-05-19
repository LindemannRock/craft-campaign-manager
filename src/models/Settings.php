<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use lindemannrock\base\traits\DateFormatSettingsTrait;
use lindemannrock\base\traits\DateRangeSettingsTrait;
use lindemannrock\base\traits\ExportFormatSettingsTrait;
use lindemannrock\base\traits\ItemsPerPageSettingsTrait;
use lindemannrock\base\traits\LogLevelSettingsTrait;
use lindemannrock\base\traits\PluginNameSettingsTrait;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings Model
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class Settings extends Model
{
    use DateFormatSettingsTrait;
    use DateRangeSettingsTrait;
    use ExportFormatSettingsTrait;
    use ItemsPerPageSettingsTrait;
    use LogLevelSettingsTrait;
    use LoggingTrait;
    use PluginNameSettingsTrait;
    use SettingsConfigTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The name of the plugin as it appears in the Control Panel menu
     */
    public string $pluginName = 'Campaign Manager';

    /**
     * @var array<int|string, string>|null Campaign type options for dropdown
     */
    public ?array $campaignTypeOptions = null;

    /**
     * @var string Route for invitation links
     */
    public string $invitationRoute = 'cm/invite';

    /**
     * @var string|null Template to use for invitation pages
     */
    public ?string $invitationTemplate = null;

    /**
     * @var int|null Default SMS Manager sender ID to use for campaigns
     * @deprecated Use defaultSenderIdHandle instead
     */
    public ?int $defaultSenderIdId = null;

    /**
     * @var string|null Default SMS Manager sender ID handle.
     *
     * Single source of truth for the campaign-manager-wide SMS routing
     * default. The provider is implicit via this sender's `providerHandle`
     * — the per-campaign `senderId` field and the `defaultSenderIdHandle`
     * here both feed into `sendWithHandle()` at dispatch.
     */
    public ?string $defaultSenderIdHandle = null;

    // =========================================================================
    // LOGGING SETTINGS
    // =========================================================================

    /**
     * @var bool Enable activity logs
     * @since 5.4.0
     */
    public bool $enableActivityLogs = true;

    /**
     * @var int Activity logs retention (days)
     * @since 5.4.0
     */
    public int $activityLogsRetention = 30;

    /**
     * @var int Activity logs limit
     * @since 5.4.0
     */
    public int $activityLogsLimit = 10000;

    /**
     * @var bool Auto trim activity logs
     * @since 5.4.0
     */
    public bool $activityAutoTrimLogs = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(static::pluginHandle());
    }

    /**
     * @inheritdoc
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'invitationRoute',
                    'invitationTemplate',
                ],
            ],
        ];
    }

    // =========================================================================
    // SETTINGS PERSISTENCE TRAIT IMPLEMENTATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected static function tableName(): string
    {
        return 'campaignmanager_settings';
    }

    /**
     * @inheritdoc
     */
    protected static function pluginHandle(): string
    {
        return 'campaign-manager';
    }

    /**
     * @inheritdoc
     */
    protected static function stringFields(): array
    {
        return [
            'pluginName',
            'invitationRoute',
            'invitationTemplate',
            'defaultSenderIdHandle',
            'logLevel',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function integerFields(): array
    {
        return [
            'defaultSenderIdId',
            'itemsPerPage',
            'activityLogsRetention',
            'activityLogsLimit',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function booleanFields(): array
    {
        return [
            'enableActivityLogs',
            'activityAutoTrimLogs',
            'showSeconds',
            'exportsCsv',
            'exportsJson',
            'exportsExcel',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function jsonFields(): array
    {
        return [
            'campaignTypeOptions',
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get campaign type options formatted for dropdowns
     */
    public function getCampaignTypeOptions(): ?array
    {
        if (!is_array($this->campaignTypeOptions)) {
            return null;
        }

        if (array_is_list($this->campaignTypeOptions)) {
            return array_combine($this->campaignTypeOptions, $this->campaignTypeOptions);
        }

        return $this->campaignTypeOptions;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return array_merge([
            ['invitationRoute', 'required'],
            ['invitationRoute', 'string'],
            ['invitationRoute', 'default', 'value' => 'cm/invite'],
            ['invitationRoute', 'match', 'pattern' => '/^[a-zA-Z0-9\-\_\/]+$/', 'message' => Craft::t('campaign-manager', 'Only letters, numbers, hyphens, underscores, and slashes are allowed.')],
            ['invitationRoute', 'validateInvitationRoute'],
            ['invitationTemplate', 'string'],
            ['defaultSenderIdId', 'integer'],
            ['defaultSenderIdHandle', 'string', 'max' => 64],
            ['enableActivityLogs', 'boolean'],
            ['activityAutoTrimLogs', 'boolean'],
            ['activityLogsRetention', 'required'],
            ['activityLogsRetention', 'integer', 'min' => 0],
            ['activityLogsRetention', 'default', 'value' => 30],
            ['activityLogsLimit', 'required'],
            ['activityLogsLimit', 'integer', 'min' => 0],
            ['activityLogsLimit', 'default', 'value' => 10000],
        ], $this->pluginNameSettingsRules(), $this->logLevelSettingsRules(), $this->itemsPerPageSettingsRules(), $this->dateFormatSettingsRules(), $this->dateRangeSettingsRules(), $this->exportFormatSettingsRules());
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge(
            $this->pluginNameSettingsLabel(),
            $this->logLevelSettingsLabel(),
            $this->itemsPerPageSettingsLabel(),
            $this->dateFormatSettingsLabels(),
            $this->dateRangeSettingsLabel(),
            $this->exportFormatSettingsLabels(),
        );
    }

    /**
     * Validate invitation route format
     */
    public function validateInvitationRoute(string $attribute): void
    {
        $value = $this->$attribute;

        if (empty($value)) {
            return;
        }

        // Remove leading/trailing slashes for validation
        $value = trim($value, '/');

        // Check for invalid patterns
        if (str_contains($value, '//')) {
            $this->addError($attribute, Craft::t('campaign-manager', 'Route cannot contain double slashes.'));
        }

        if (preg_match('/\s/', $value)) {
            $this->addError($attribute, Craft::t('campaign-manager', 'Route cannot contain spaces.'));
        }
    }
}
