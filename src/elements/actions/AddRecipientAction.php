<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\elements\actions;

use Craft;
use craft\base\ElementAction;

/**
 * Add Recipient Action
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.1.0
 */
class AddRecipientAction extends ElementAction
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign-manager', 'Add Recipient');
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: false,
        activate: (\$selectedItems) => {
            const campaignId = \$selectedItems.find('.element').data('id');
            const siteId = \$selectedItems.find('.element').data('site-id');
            const site = Craft.sites.find(s => s.id == siteId);
            const siteHandle = site ? site.handle : 'en';
            window.location.href = Craft.getCpUrl('campaign-manager/campaigns/' + campaignId + '/add-recipient', { site: siteHandle });
        },
    });
})();
JS, [static::class]);

        return null;
    }
}
