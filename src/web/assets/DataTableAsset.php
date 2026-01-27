<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\web\assets;

use craft\web\AssetBundle;

/**
 * DataTable Asset Bundle
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class DataTableAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@lindemannrock/campaignmanager/resources/';

        $this->css = [
            'css/datatables.min.css',
        ];

        $this->js = [
            'js/datatables.min.js',
        ];

        parent::init();
    }
}
