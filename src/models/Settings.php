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
use craft\elements\Entry;
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
    use LoggingTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;
    use SettingsConfigTrait;

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The public-facing name of the plugin
     */
    public string $pluginName = 'Campaign Manager';

    /**
     * @var array<int|string, string>|null Campaign type options for dropdown
     */
    public ?array $campaignTypeOptions = null;

    /**
     * @var string Element type to use for campaigns
     */
    public string $campaignElementType = Entry::class;

    /**
     * @var string|null Section handle to filter campaigns (e.g., 'surveys')
     */
    public ?string $campaignSectionHandle = null;

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
     * @var string|null Default SMS Manager provider handle
     */
    public ?string $defaultProviderHandle = null;

    /**
     * @var string|null Default SMS Manager sender ID handle
     */
    public ?string $defaultSenderIdHandle = null;

    // =========================================================================
    // INTERFACE SETTINGS
    // =========================================================================

    /**
     * @var int Number of items to display per page in lists
     */
    public int $itemsPerPage = 50;

    // =========================================================================
    // LOGGING SETTINGS
    // =========================================================================

    /**
     * @var string Log level for the logging library
     */
    public string $logLevel = 'error';

    /**
     * @var bool Enable activity logs
     */
    public bool $enableActivityLogs = true;

    /**
     * @var int Activity logs retention (days)
     */
    public int $activityLogsRetention = 30;

    /**
     * @var int Activity logs limit
     */
    public int $activityLogsLimit = 10000;

    /**
     * @var bool Auto trim activity logs
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
            'campaignElementType',
            'campaignSectionHandle',
            'invitationRoute',
            'invitationTemplate',
            'defaultProviderHandle',
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
     *
     * @since 5.0.0
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
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'Campaign Manager'],
            ['invitationRoute', 'required'],
            ['invitationRoute', 'string'],
            ['invitationRoute', 'default', 'value' => 'cm/invite'],
            ['invitationRoute', 'match', 'pattern' => '/^[a-zA-Z0-9\-\_\/]+$/', 'message' => Craft::t('campaign-manager', 'Only letters, numbers, hyphens, underscores, and slashes are allowed.')],
            ['invitationRoute', 'validateInvitationRoute'],
            ['invitationTemplate', 'string'],
            ['campaignElementType', 'string'],
            ['defaultSenderIdId', 'integer'],
            [['defaultProviderHandle', 'defaultSenderIdHandle'], 'string', 'max' => 64],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            ['itemsPerPage', 'required'],
            ['itemsPerPage', 'integer', 'min' => 10, 'max' => 500],
            ['itemsPerPage', 'default', 'value' => 50],
            ['enableActivityLogs', 'boolean'],
            ['activityAutoTrimLogs', 'boolean'],
            ['activityLogsRetention', 'required'],
            ['activityLogsRetention', 'integer', 'min' => 0],
            ['activityLogsRetention', 'default', 'value' => 30],
            ['activityLogsLimit', 'required'],
            ['activityLogsLimit', 'integer', 'min' => 0],
            ['activityLogsLimit', 'default', 'value' => 10000],
        ];
    }

    /**
     * Validate invitation route format
     *
     * @since 5.0.0
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
