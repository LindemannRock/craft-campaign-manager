<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\models;

use craft\base\Model;
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
     */
    public ?int $defaultSenderIdId = null;

    /**
     * @var string Default country code for phone validation (ISO 3166-1 alpha-2)
     */
    public string $defaultCountryCode = 'KW';

    // =========================================================================
    // LOGGING SETTINGS
    // =========================================================================

    /**
     * @var string Log level for the logging library
     */
    public string $logLevel = 'error';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('campaign-manager');
    }

    // =========================================================================
    // SETTINGS PERSISTENCE TRAIT IMPLEMENTATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected static function tableName(): string
    {
        return '{{%campaignmanager_settings}}';
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
            'defaultCountryCode',
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
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function booleanFields(): array
    {
        return [];
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
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'Campaign Manager'],
            ['invitationRoute', 'string'],
            ['invitationRoute', 'default', 'value' => 'cm/invite'],
            ['invitationTemplate', 'string'],
            ['campaignElementType', 'string'],
            ['defaultSenderIdId', 'integer'],
            ['defaultCountryCode', 'string', 'length' => 2],
            ['defaultCountryCode', 'default', 'value' => 'KW'],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
        ];
    }
}
