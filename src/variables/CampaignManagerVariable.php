<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\variables;

use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\services\CampaignsService;
use lindemannrock\campaignmanager\services\RecipientsService;
use lindemannrock\campaignmanager\services\SmsService;
use yii\base\Behavior;

/**
 * Campaign Manager Variable
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class CampaignManagerVariable extends Behavior
{
    /**
     * Get the campaigns service
     */
    public function getCampaigns(): CampaignsService
    {
        return CampaignManager::$plugin->campaigns;
    }

    /**
     * Get the recipients service
     */
    public function getRecipients(): RecipientsService
    {
        return CampaignManager::$plugin->recipients;
    }

    /**
     * Get the SMS service
     */
    public function getSms(): SmsService
    {
        return CampaignManager::$plugin->sms;
    }

    /**
     * Get the analytics service
     *
     * @since 5.1.0
     */
    public function getAnalytics(): AnalyticsService
    {
        return CampaignManager::$plugin->analytics;
    }
}
