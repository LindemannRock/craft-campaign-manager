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
        $this->setLoggingHandle('campaign-manager');
    }

    /**
     * Get a campaign element query
     *
     * @since 5.0.0
     */
    public function find(): ElementQueryInterface
    {
        $settings = CampaignManager::$plugin->getSettings();
        $elementType = $settings->campaignElementType ?: \lindemannrock\campaignmanager\elements\Campaign::class;

        $query = $elementType::find();

        // Filter by section if configured (only for Entry-based campaigns)
        if (!empty($settings->campaignSectionHandle) && $elementType === \craft\elements\Entry::class) {
            $query->section($settings->campaignSectionHandle);
        }

        return $query;
    }

    /**
     * Get a campaign by ID
     *
     * @since 5.0.0
     */
    public function getCampaignById(int $id): ?\lindemannrock\campaignmanager\elements\Campaign
    {
        return \lindemannrock\campaignmanager\elements\Campaign::find()
            ->id($id)
            ->status(null)
            ->one();
    }
}
