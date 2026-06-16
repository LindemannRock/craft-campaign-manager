<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\widgets;

use Craft;
use craft\base\Widget;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\CampaignManager;

/**
 * Campaign Manager analytics summary dashboard widget.
 *
 * @since 5.13.0
 */
class AnalyticsSummaryWidget extends Widget
{
    use SiteFilterTrait;

    /**
     * @var string Date range for the widget metrics
     */
    public string $dateRange = 'last7days';

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['dateRange'], 'in', 'range' => array_keys(DateRangeHelper::getOptions('assoc'))];
        $rules[] = [['siteId'], 'in', 'range' => array_column($this->siteOptions(), 'value')];
        $rules[] = [['dateRange'], 'default', 'value' => 'last7days'];
        $rules[] = [['siteId'], 'default', 'value' => 'all'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = CampaignManager::$plugin->getSettings()->getFullName();

        return $pluginName . ' - ' . Craft::t('campaign-manager', 'Analytics');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission('campaignManager:viewAnalytics');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@lindemannrock/campaignmanager/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 2;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        return self::displayName();
    }

    /**
     * @inheritdoc
     */
    public function getSubtitle(): ?string
    {
        $labels = DateRangeHelper::getOptions('assoc');

        return $labels[$this->dateRange] ?? $labels['last7days'];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('campaign-manager/widgets/analytics-summary/settings', [
            'widget' => $this,
            'siteOptions' => $this->siteOptions(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission('campaignManager:viewAnalytics')) {
            return Craft::$app->getView()->renderTemplate('lindemannrock-base/_components/dashboard-widget-empty', [
                'title' => Craft::t('campaign-manager', 'No data available'),
            ]);
        }

        $stats = CampaignManager::$plugin->analytics->getOverviewStats('all', $this->effectiveSiteId(), $this->dateRange);

        return Craft::$app->getView()->renderTemplate('campaign-manager/widgets/analytics-summary/body', [
            'stats' => $stats,
            'dateRange' => $this->dateRange,
            'siteId' => $this->siteId,
        ]);
    }
}
