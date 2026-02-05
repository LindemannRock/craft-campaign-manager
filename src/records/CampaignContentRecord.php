<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use craft\db\ActiveRecord;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * Campaign Content Record (translatable fields)
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 *
 * @property int $id
 * @property int $campaignId
 * @property int $siteId
 * @property string|null $emailInvitationMessage
 * @property string|null $emailInvitationSubject
 * @property string|null $smsInvitationMessage
 * @property CampaignRecord $campaign
 * @property Site $site
 */
class CampaignContentRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%campaignmanager_campaigns_content}}';
    }

    /**
     * Returns the campaign.
     *
     * @since 5.0.0
     */
    public function getCampaign(): ActiveQueryInterface
    {
        return $this->hasOne(CampaignRecord::class, ['id' => 'campaignId']);
    }

    /**
     * Returns the site.
     *
     * @since 5.0.0
     */
    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
