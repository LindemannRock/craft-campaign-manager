<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * Activity log record
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use craft\db\ActiveRecord;

/**
 * Activity log record
 *
 * @property int $id
 * @property int|null $userId
 * @property int|null $campaignId
 * @property int|null $recipientId
 * @property string $action
 * @property string $source
 * @property string|null $summary
 * @property string|null $details
 * @property \DateTime|null $dateCreated
 * @property \DateTime|null $dateUpdated
 * @property string|null $uid
 *
 * @since 5.4.0
 */
class ActivityLogRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%campaignmanager_activity_logs}}';
    }
}
