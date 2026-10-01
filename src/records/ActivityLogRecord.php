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
 * @property string $siteScope
 * @property \DateTime|null $dateCreated
 * @property \DateTime|null $dateUpdated
 * @property string|null $uid
 *
 * @since 5.4.0
 */
class ActivityLogRecord extends ActiveRecord
{
    /**
     * The log covers exactly the sites in its site rows.
     *
     * @since 5.16.0
     */
    public const SCOPE_SITES = 'sites';

    /**
     * The log covers every site of the project.
     *
     * @since 5.16.0
     */
    public const SCOPE_ALL = 'all';

    /**
     * The sites the log covers are not known. Logs written before site scope
     * was recorded have this scope.
     *
     * @since 5.16.0
     */
    public const SCOPE_UNKNOWN = 'unknown';

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%campaignmanager_activity_logs}}';
    }
}
