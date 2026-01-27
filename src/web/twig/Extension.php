<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\web\twig;

use lindemannrock\campaignmanager\CampaignManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig Extension
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class Extension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @inheritdoc
     */
    public function getGlobals(): array
    {
        return [
            'campaignManager' => CampaignManager::$plugin,
        ];
    }
}
