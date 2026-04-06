<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\services;

use craft\base\Component;
use craft\elements\db\ElementQueryInterface;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Campaigns Service
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class CampaignsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * Get a campaign element query
     */
    public function find(): ElementQueryInterface
    {
        return Campaign::find();
    }

    /**
     * Get a campaign by ID
     */
    public function getCampaignById(int $id): ?\lindemannrock\campaignmanager\elements\Campaign
    {
        return \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($id)
            ->status(null)
            ->one();
    }
}
