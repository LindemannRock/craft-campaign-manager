<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\widgets;

use Craft;
use lindemannrock\campaignmanager\helpers\SiteAccessHelper;

/**
 * Shared site filter behavior for Campaign Manager dashboard widgets.
 *
 * @since 5.13.0
 */
trait SiteFilterTrait
{
    /**
     * @var string Selected site ID, or "all" for all editable sites
     */
    public string $siteId = 'all';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function siteOptions(): array
    {
        $options = [
            ['value' => 'all', 'label' => Craft::t('campaign-manager', 'All Sites')],
        ];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $options[] = [
                'value' => (string) $site->id,
                'label' => $site->name,
            ];
        }

        return $options;
    }

    /**
     * Get the sites to show, checked against the sites the user can edit now.
     *
     * The stored site may have been chosen before access to it was removed.
     *
     * @return int|array<int>|null Null when there is no editable site to show
     */
    protected function effectiveSiteId(): int|array|null
    {
        $editableSiteIds = SiteAccessHelper::editableSiteIds();

        if ($this->siteId === 'all') {
            return $editableSiteIds !== [] ? $editableSiteIds : null;
        }

        $siteId = (int) $this->siteId;

        return in_array($siteId, $editableSiteIds, true) ? $siteId : null;
    }
}
