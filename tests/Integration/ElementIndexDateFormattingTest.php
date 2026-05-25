<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\models\Settings;
use lindemannrock\campaignmanager\tests\TestCase;

/**
 * Pins Campaign element-index date columns to the plugin date-format cascade.
 *
 * Craft 5 routes element-index column rendering through `attributeHtml()` using
 * Craft's own core controller — auto-detection of the active plugin handle fails
 * there. The fix passes `pluginHandle: 'campaign-manager'` explicitly so the
 * per-plugin cascade settings (month format, date order, date separator, etc.)
 * take effect on the element index just as they do on every other CP surface.
 *
 * @since 5.11.0
 */
final class ElementIndexDateFormattingTest extends TestCase
{
    public function testDateColumnsUseCampaignManagerCascadeSettings(): void
    {
        /** @var Settings $settings */
        $settings = CampaignManager::$plugin->getSettings();
        $previous = [
            'timeFormat' => $settings->timeFormat,
            'monthFormat' => $settings->monthFormat,
            'dateOrder' => $settings->dateOrder,
            'dateSeparator' => $settings->dateSeparator,
            'showSeconds' => $settings->showSeconds,
        ];

        $settings->timeFormat = '12';
        $settings->monthFormat = 'long';
        $settings->dateOrder = 'mdy';
        $settings->dateSeparator = '/';
        $settings->showSeconds = true;
        DateFormatHelper::clearConfigCache();

        try {
            $campaign = new Campaign();
            $campaign->dateCreated = new DateTime('2026-01-07 14:30:25', new DateTimeZone('UTC'));
            $campaign->dateUpdated = new DateTime('2026-03-15 09:05:00', new DateTimeZone('UTC'));

            $createdHtml = $campaign->getAttributeHtml('dateCreated');
            $updatedHtml = $campaign->getAttributeHtml('dateUpdated');

            // With monthFormat=long the month name is spelled out fully.
            $this->assertStringContainsString('January', $createdHtml);
            $this->assertStringContainsString('March', $updatedHtml);

            // The title attribute carries the full datetime (cascade-formatted).
            $this->assertStringContainsString('title=', $createdHtml);
            $this->assertStringContainsString('title=', $updatedHtml);
        } finally {
            $settings->timeFormat = $previous['timeFormat'];
            $settings->monthFormat = $previous['monthFormat'];
            $settings->dateOrder = $previous['dateOrder'];
            $settings->dateSeparator = $previous['dateSeparator'];
            $settings->showSeconds = $previous['showSeconds'];
            DateFormatHelper::clearConfigCache();
        }
    }
}
