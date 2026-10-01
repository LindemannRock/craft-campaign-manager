<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\helpers;

use Craft;
use craft\models\Site;
use yii\web\ForbiddenHttpException;

/**
 * Keeps control panel work inside the sites the current user may edit.
 *
 * Craft decides which sites those are. A Campaign Manager permission allows
 * an action, but only on these sites.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.16.0
 */
class SiteAccessHelper
{
    /**
     * Get the IDs of the sites the current user may edit
     *
     * @return array<int>
     */
    public static function editableSiteIds(): array
    {
        return array_values(array_map('intval', Craft::$app->getSites()->getEditableSiteIds()));
    }

    /**
     * Whether the current user may edit the site
     */
    public static function canEditSite(int $siteId): bool
    {
        return in_array($siteId, self::editableSiteIds(), true);
    }

    /**
     * Get the IDs of every site the project currently has, disabled sites
     * included.
     *
     * This is the set of sites an operation can touch and the set a user must
     * be able to edit to count as having access to every site.
     *
     * @return array<int>
     */
    public static function allSiteIds(): array
    {
        return array_values(array_map('intval', Craft::$app->getSites()->getAllSiteIds(true)));
    }

    /**
     * Whether the current user may edit every site the project currently has
     */
    public static function canEditEverySite(): bool
    {
        return array_diff(self::allSiteIds(), self::editableSiteIds()) === [];
    }

    /**
     * Resolve the site an action works with, and require that the current
     * user may edit it.
     *
     * Without a requested site the current site is used, or the first
     * editable site when the current one is not editable. A site that does
     * not exist is refused the same way as one the user may not edit, so the
     * answer never reveals which sites exist.
     *
     * @param mixed $requested Site handle or ID, as sent with the request
     * @throws ForbiddenHttpException if the site is not editable
     */
    public static function requireEditableSite(mixed $requested = null): Site
    {
        $sites = Craft::$app->getSites();
        $editableSiteIds = self::editableSiteIds();
        $site = null;

        if ($requested === null || $requested === '') {
            $site = $sites->getCurrentSite();
            if (!in_array((int)$site->id, $editableSiteIds, true)) {
                $site = $editableSiteIds !== [] ? $sites->getSiteById($editableSiteIds[0]) : null;
            }
        } elseif (is_int($requested) || (is_string($requested) && ctype_digit($requested))) {
            $site = $sites->getSiteById((int)$requested);
        } elseif (is_string($requested)) {
            $site = $sites->getSiteByHandle($requested);
        }

        if ($site === null || !in_array((int)$site->id, $editableSiteIds, true)) {
            throw new ForbiddenHttpException(Craft::t('campaign-manager', 'User does not have permission to access this area.'));
        }

        return $site;
    }
}
