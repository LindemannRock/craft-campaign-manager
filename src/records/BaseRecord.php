<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use craft\db\ActiveRecord;
use craft\helpers\DateTimeHelper;

/**
 * Base Record
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
abstract class BaseRecord extends ActiveRecord
{
    public const TABLE_NAME = '';

    /**
     * @var string[] Attributes that should be converted to DateTime objects
     */
    protected array $dateTimeAttributes = [];

    /**
     * Get the full column name with table prefix
     */
    public static function columnName(string $column): string
    {
        return self::tableName() . '.' . $column;
    }

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%' . static::TABLE_NAME . '}}';
    }

    /**
     * @inheritdoc
     */
    public function __get($name): mixed
    {
        if (in_array($name, $this->dateTimeAttributes, true)) {
            $value = parent::__get($name);
            if ($value !== null) {
                return DateTimeHelper::toDateTime($value);
            }
        }

        return parent::__get($name);
    }
}
