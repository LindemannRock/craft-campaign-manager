<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

/**
 * Analytics Record
 *
 * Stores aggregated campaign analytics per day.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.1.0
 *
 * @property int $id
 * @property int $campaignId
 * @property int $siteId
 * @property string $date
 * @property int $totalRecipients
 * @property int $emailsSent
 * @property int $smsSent
 * @property int $emailsOpened
 * @property int $smsOpened
 * @property int $submissions
 * @property int $expired
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class AnalyticsRecord extends BaseRecord
{
    public const TABLE_NAME = 'campaignmanager_analytics';

    /**
     * Get the campaign for this analytics record
     *
     * @return \yii\db\ActiveQuery
     * @since 5.1.0
     */
    public function getCampaign(): \yii\db\ActiveQuery
    {
        return $this->hasOne(CampaignRecord::class, ['id' => 'campaignId']);
    }

    /**
     * Get total invitations sent (email + SMS)
     *
     * @return int
     * @since 5.1.0
     */
    public function getTotalSent(): int
    {
        return $this->emailsSent + $this->smsSent;
    }

    /**
     * Get total opens (email + SMS)
     *
     * @return int
     * @since 5.1.0
     */
    public function getTotalOpened(): int
    {
        return $this->emailsOpened + $this->smsOpened;
    }

    /**
     * Get open rate as percentage
     *
     * @return float
     * @since 5.1.0
     */
    public function getOpenRate(): float
    {
        $sent = $this->getTotalSent();
        if ($sent === 0) {
            return 0.0;
        }

        return round(($this->getTotalOpened() / $sent) * 100, 2);
    }

    /**
     * Get conversion rate as percentage (submissions / total recipients)
     *
     * @return float
     * @since 5.1.0
     */
    public function getConversionRate(): float
    {
        if ($this->totalRecipients === 0) {
            return 0.0;
        }

        return round(($this->submissions / $this->totalRecipients) * 100, 2);
    }
}
