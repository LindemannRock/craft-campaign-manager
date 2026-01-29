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
 * View Recipients Action
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.1.0
 */
class ViewRecipientsAction extends ElementAction
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign-manager', 'View Recipients');
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
            window.location.href = Craft.getCpUrl('campaign-manager/campaigns/' + campaignId + '/recipients', { site: siteHandle });
        },
    });
})();
JS, [static::class]);

        return null;
    }
}
