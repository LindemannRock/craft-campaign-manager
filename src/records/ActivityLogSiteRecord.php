<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * Activity log site record
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use craft\db\ActiveRecord;

/**
 * One site an activity log covers
 *
 * The site ID becomes null when the site is deleted. The row is kept so the
 * log still counts that site as one it covered.
 *
 * @property int $id
 * @property int $logId
 * @property int|null $siteId
 * @property \DateTime|null $dateCreated
 * @property \DateTime|null $dateUpdated
 * @property string|null $uid
 *
 * @since 5.16.0
 */
class ActivityLogSiteRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%campaignmanager_activity_log_sites}}';
    }
}
