<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\AnalyticsRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Analytics Service
 *
 * Provides campaign analytics and statistics.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.1.0
 */
class AnalyticsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('campaign-manager');
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     * @since 5.1.0
     */
    public function getDateRangeFromParam(string $dateRange): array
    {
        $endDate = new \DateTime();

        $startDate = match ($dateRange) {
            'today' => new \DateTime(),
            'yesterday' => (new \DateTime())->modify('-1 day'),
            'last7days' => (new \DateTime())->modify('-7 days'),
            'last30days' => (new \DateTime())->modify('-30 days'),
            'last90days' => (new \DateTime())->modify('-90 days'),
            'all' => (new \DateTime())->modify('-365 days'),
            default => (new \DateTime())->modify('-30 days'),
        };

        if ($dateRange === 'yesterday') {
            $endDate = (new \DateTime())->modify('-1 day');
        }

        return ['start' => $startDate, 'end' => $endDate];
    }

    /**
     * Get overview statistics
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, int|float>
     * @since 5.1.0
     */
    public function getOverviewStats(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dates['start'], $dates['end']);

        // Total recipients
        $totalRecipients = (clone $query)->count();

        // Emails sent
        $emailsSent = (clone $query)
            ->andWhere(['not', ['emailSendDate' => null]])
            ->count();

        // SMS sent
        $smsSent = (clone $query)
            ->andWhere(['not', ['smsSendDate' => null]])
            ->count();

        // Emails opened
        $emailsOpened = (clone $query)
            ->andWhere(['not', ['emailOpenDate' => null]])
            ->count();

        // SMS opened
        $smsOpened = (clone $query)
            ->andWhere(['not', ['smsOpenDate' => null]])
            ->count();

        // Submissions
        $submissions = (clone $query)
            ->andWhere(['not', ['submissionId' => null]])
            ->count();

        // Expired
        $expired = (clone $query)
            ->andWhere(['<', 'invitationExpiryDate', (new \DateTime())->format('Y-m-d H:i:s')])
            ->andWhere(['submissionId' => null])
            ->count();

        // Calculate rates (capped at 100% to handle edge cases)
        $totalSent = $emailsSent + $smsSent;
        $totalOpened = $emailsOpened + $smsOpened;
        $openRate = $totalSent > 0 ? min(100, round(($totalOpened / $totalSent) * 100, 1)) : 0;
        $conversionRate = $totalRecipients > 0 ? min(100, round(($submissions / $totalRecipients) * 100, 1)) : 0;
        $emailOpenRate = $emailsSent > 0 ? min(100, round(($emailsOpened / $emailsSent) * 100, 1)) : 0;
        $smsOpenRate = $smsSent > 0 ? min(100, round(($smsOpened / $smsSent) * 100, 1)) : 0;

        return [
            'totalRecipients' => (int)$totalRecipients,
            'emailsSent' => (int)$emailsSent,
            'smsSent' => (int)$smsSent,
            'emailsOpened' => (int)$emailsOpened,
            'smsOpened' => (int)$smsOpened,
            'submissions' => (int)$submissions,
            'expired' => (int)$expired,
            'totalSent' => $totalSent,
            'totalOpened' => $totalOpened,
            'openRate' => $openRate,
            'conversionRate' => $conversionRate,
            'emailOpenRate' => $emailOpenRate,
            'smsOpenRate' => $smsOpenRate,
        ];
    }

    /**
     * Get daily trend data
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     * @since 5.1.0
     */
    public function getDailyTrend(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dates['start'], $dates['end']);

        // Get daily counts
        $data = (clone $query)
            ->select([
                'DATE(dateCreated) as date',
                'COUNT(*) as recipients',
                'SUM(CASE WHEN emailSendDate IS NOT NULL THEN 1 ELSE 0 END) as emailsSent',
                'SUM(CASE WHEN smsSendDate IS NOT NULL THEN 1 ELSE 0 END) as smsSent',
                'SUM(CASE WHEN submissionId IS NOT NULL THEN 1 ELSE 0 END) as submissions',
            ])
            ->groupBy(['DATE(dateCreated)'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Fill in missing dates
        $chartData = [];
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $dayData = array_filter($data, fn($row) => $row['date'] === $dateStr);
            $dayData = $dayData ? array_values($dayData)[0] : null;

            $chartData[] = [
                'date' => $date->format('M j'),
                'recipients' => (int)($dayData['recipients'] ?? 0),
                'emailsSent' => (int)($dayData['emailsSent'] ?? 0),
                'smsSent' => (int)($dayData['smsSent'] ?? 0),
                'submissions' => (int)($dayData['submissions'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'recipients' => array_column($chartData, 'recipients'),
            'emailsSent' => array_column($chartData, 'emailsSent'),
            'smsSent' => array_column($chartData, 'smsSent'),
            'submissions' => array_column($chartData, 'submissions'),
        ];
    }

    /**
     * Get channel distribution data
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     * @since 5.1.0
     */
    public function getChannelDistribution(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dates['start'], $dates['end']);

        // Email only (email sent, SMS not sent)
        $emailOnly = (clone $query)
            ->andWhere(['not', ['emailSendDate' => null]])
            ->andWhere(['smsSendDate' => null])
            ->count();

        // SMS only (SMS sent, email not sent)
        $smsOnly = (clone $query)
            ->andWhere(['not', ['smsSendDate' => null]])
            ->andWhere(['emailSendDate' => null])
            ->count();

        // Both (both email and SMS sent)
        $both = (clone $query)
            ->andWhere(['not', ['emailSendDate' => null]])
            ->andWhere(['not', ['smsSendDate' => null]])
            ->count();

        return [
            'labels' => [
                Craft::t('campaign-manager', 'Email Only'),
                Craft::t('campaign-manager', 'SMS Only'),
                Craft::t('campaign-manager', 'Both'),
            ],
            'values' => [(int)$emailOnly, (int)$smsOnly, (int)$both],
        ];
    }

    /**
     * Get engagement over time data
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     * @since 5.1.0
     */
    public function getEngagementOverTime(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dates['start'], $dates['end']);

        // Get daily opens
        $emailOpens = (clone $query)
            ->select([
                'DATE(emailOpenDate) as date',
                'COUNT(*) as count',
            ])
            ->andWhere(['not', ['emailOpenDate' => null]])
            ->groupBy(['DATE(emailOpenDate)'])
            ->indexBy('date')
            ->column();

        $smsOpens = (clone $query)
            ->select([
                'DATE(smsOpenDate) as date',
                'COUNT(*) as count',
            ])
            ->andWhere(['not', ['smsOpenDate' => null]])
            ->groupBy(['DATE(smsOpenDate)'])
            ->indexBy('date')
            ->column();

        // Fill in missing dates
        $chartData = [];
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $chartData[] = [
                'date' => $date->format('M j'),
                'emailOpens' => (int)($emailOpens[$dateStr] ?? 0),
                'smsOpens' => (int)($smsOpens[$dateStr] ?? 0),
            ];
            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'emailOpens' => array_column($chartData, 'emailOpens'),
            'smsOpens' => array_column($chartData, 'smsOpens'),
        ];
    }

    /**
     * Get conversion funnel data
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     * @since 5.1.0
     */
    public function getConversionFunnel(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dates['start'], $dates['end']);

        $totalRecipients = (clone $query)->count();

        $invited = (clone $query)
            ->andWhere([
                'or',
                ['not', ['emailSendDate' => null]],
                ['not', ['smsSendDate' => null]],
            ])
            ->count();

        $opened = (clone $query)
            ->andWhere([
                'or',
                ['not', ['emailOpenDate' => null]],
                ['not', ['smsOpenDate' => null]],
            ])
            ->count();

        $submitted = (clone $query)
            ->andWhere(['not', ['submissionId' => null]])
            ->count();

        return [
            'labels' => [
                Craft::t('campaign-manager', 'Total Recipients'),
                Craft::t('campaign-manager', 'Invitations Sent'),
                Craft::t('campaign-manager', 'Opened'),
                Craft::t('campaign-manager', 'Submitted'),
            ],
            'values' => [
                (int)$totalRecipients,
                (int)$invited,
                (int)$opened,
                (int)$submitted,
            ],
        ];
    }

    /**
     * Get campaign breakdown data
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param string $dateRange Date range parameter
     * @return array<int, array<string, mixed>>
     * @since 5.1.0
     */
    public function getCampaignBreakdown(int|string $campaignId, int|string $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);

        $campaignQuery = Campaign::find();
        if ($siteId !== 'all') {
            $campaignQuery->siteId($siteId);
        }
        // Filter by specific campaign if selected
        if ($campaignId !== 'all') {
            $campaignQuery->id($campaignId);
        }
        $campaigns = $campaignQuery->all();

        $result = [];
        foreach ($campaigns as $campaign) {
            $query = $this->buildRecipientQuery($campaign->id, $siteId, $dates['start'], $dates['end']);

            $totalRecipients = (clone $query)->count();
            $submissions = (clone $query)
                ->andWhere(['not', ['submissionId' => null]])
                ->count();

            $result[] = [
                'campaignId' => $campaign->id,
                'campaignName' => $campaign->title,
                'totalRecipients' => (int)$totalRecipients,
                'submissions' => (int)$submissions,
                'conversionRate' => $totalRecipients > 0 ? round(($submissions / $totalRecipients) * 100, 1) : 0,
            ];
        }

        // Sort by total recipients descending
        usort($result, fn($a, $b) => $b['totalRecipients'] <=> $a['totalRecipients']);

        return $result;
    }

    /**
     * Get all campaigns for filter dropdown
     *
     * @param int|string $siteId Site ID or 'all'
     * @return array<int, array{value: int|string, label: string}>
     * @since 5.1.0
     */
    public function getCampaignOptions(int|string $siteId): array
    {
        $campaignQuery = Campaign::find()->status(null);
        if ($siteId !== 'all') {
            $campaignQuery->siteId($siteId);
        }
        $campaigns = $campaignQuery->orderBy(['title' => SORT_ASC])->all();

        $options = [
            ['value' => 'all', 'label' => Craft::t('campaign-manager', 'All Campaigns')],
        ];

        foreach ($campaigns as $campaign) {
            $options[] = [
                'value' => $campaign->id,
                'label' => $campaign->title,
            ];
        }

        return $options;
    }

    /**
     * Get per-campaign statistics (alias for template use)
     *
     * @param int $campaignId Campaign ID
     * @param int|null $siteId Site ID or null for all sites
     * @param string $dateRange Date range parameter
     * @return array<string, int|float>
     * @since 5.1.0
     */
    public function getCampaignStats(int $campaignId, ?int $siteId, string $dateRange): array
    {
        return $this->getOverviewStats($campaignId, $siteId ?? 'all', $dateRange);
    }

    /**
     * Get per-campaign daily trend data (alias for template use)
     *
     * @param int $campaignId Campaign ID
     * @param int|null $siteId Site ID or null for all sites
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     * @since 5.1.0
     */
    public function getCampaignDailyTrend(int $campaignId, ?int $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId ?? 'all', $dates['start'], $dates['end']);

        // Get daily sent counts (using send dates, not dateCreated)
        $sentData = [];
        $openedData = [];
        $submissionData = [];

        // Build date array first
        $dateLabels = [];
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $dateLabels[$dateStr] = $date->format('M j');
            $sentData[$dateStr] = 0;
            $openedData[$dateStr] = 0;
            $submissionData[$dateStr] = 0;
            $date->modify('+1 day');
        }

        // Get SMS sent by date
        $smsSentByDate = (clone $query)
            ->select(['DATE(smsSendDate) as date', 'COUNT(*) as count'])
            ->andWhere(['not', ['smsSendDate' => null]])
            ->groupBy(['DATE(smsSendDate)'])
            ->all();

        foreach ($smsSentByDate as $row) {
            if (isset($sentData[$row['date']])) {
                $sentData[$row['date']] += (int)$row['count'];
            }
        }

        // Get email sent by date
        $emailSentByDate = (clone $query)
            ->select(['DATE(emailSendDate) as date', 'COUNT(*) as count'])
            ->andWhere(['not', ['emailSendDate' => null]])
            ->groupBy(['DATE(emailSendDate)'])
            ->all();

        foreach ($emailSentByDate as $row) {
            if (isset($sentData[$row['date']])) {
                $sentData[$row['date']] += (int)$row['count'];
            }
        }

        // Get opened by date (SMS)
        $smsOpenedByDate = (clone $query)
            ->select(['DATE(smsOpenDate) as date', 'COUNT(*) as count'])
            ->andWhere(['not', ['smsOpenDate' => null]])
            ->groupBy(['DATE(smsOpenDate)'])
            ->all();

        foreach ($smsOpenedByDate as $row) {
            if (isset($openedData[$row['date']])) {
                $openedData[$row['date']] += (int)$row['count'];
            }
        }

        // Get opened by date (Email)
        $emailOpenedByDate = (clone $query)
            ->select(['DATE(emailOpenDate) as date', 'COUNT(*) as count'])
            ->andWhere(['not', ['emailOpenDate' => null]])
            ->groupBy(['DATE(emailOpenDate)'])
            ->all();

        foreach ($emailOpenedByDate as $row) {
            if (isset($openedData[$row['date']])) {
                $openedData[$row['date']] += (int)$row['count'];
            }
        }

        // Get submissions - we need to join with formie submissions to get the actual submission date
        // For now, use dateUpdated as a proxy when submissionId is set
        $submissionsByDate = (clone $query)
            ->select(['DATE(dateUpdated) as date', 'COUNT(*) as count'])
            ->andWhere(['not', ['submissionId' => null]])
            ->groupBy(['DATE(dateUpdated)'])
            ->all();

        foreach ($submissionsByDate as $row) {
            if (isset($submissionData[$row['date']])) {
                $submissionData[$row['date']] = (int)$row['count'];
            }
        }

        return [
            'labels' => array_values($dateLabels),
            'sent' => array_values($sentData),
            'opened' => array_values($openedData),
            'submissions' => array_values($submissionData),
        ];
    }

    /**
     * Build a recipient query with common filters
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string $siteId Site ID or 'all'
     * @param \DateTime $startDate Start date
     * @param \DateTime $endDate End date
     * @return Query
     */
    private function buildRecipientQuery(int|string $campaignId, int|string $siteId, \DateTime $startDate, \DateTime $endDate): Query
    {
        $query = (new Query())
            ->from(RecipientRecord::tableName())
            ->where(['>=', 'dateCreated', $startDate->format('Y-m-d 00:00:00')])
            ->andWhere(['<=', 'dateCreated', $endDate->format('Y-m-d 23:59:59')]);

        if ($campaignId !== 'all') {
            $query->andWhere(['campaignId' => $campaignId]);
        }

        if ($siteId !== 'all') {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query;
    }

    /**
     * Refresh statistics for a campaign
     *
     * Recalculates and stores aggregated statistics.
     *
     * @param int $campaignId Campaign ID
     * @param int $siteId Site ID
     * @param \DateTime|null $date Specific date to refresh, or null for today
     * @since 5.1.0
     */
    public function refreshStatistics(int $campaignId, int $siteId, ?\DateTime $date = null): void
    {
        $date = $date ?? new \DateTime();
        $dateStr = $date->format('Y-m-d');

        // Build query for this campaign/site/date
        $query = (new Query())
            ->from(RecipientRecord::tableName())
            ->where(['campaignId' => $campaignId])
            ->andWhere(['siteId' => $siteId])
            ->andWhere(['>=', 'dateCreated', $dateStr . ' 00:00:00'])
            ->andWhere(['<=', 'dateCreated', $dateStr . ' 23:59:59']);

        // Calculate metrics
        $totalRecipients = (clone $query)->count();
        $emailsSent = (clone $query)->andWhere(['not', ['emailSendDate' => null]])->count();
        $smsSent = (clone $query)->andWhere(['not', ['smsSendDate' => null]])->count();
        $emailsOpened = (clone $query)->andWhere(['not', ['emailOpenDate' => null]])->count();
        $smsOpened = (clone $query)->andWhere(['not', ['smsOpenDate' => null]])->count();
        $submissions = (clone $query)->andWhere(['not', ['submissionId' => null]])->count();
        $expired = (clone $query)
            ->andWhere(['<', 'invitationExpiryDate', (new \DateTime())->format('Y-m-d H:i:s')])
            ->andWhere(['submissionId' => null])
            ->count();

        // Find or create record
        /** @var AnalyticsRecord|null $record */
        $record = AnalyticsRecord::find()
            ->where([
                'campaignId' => $campaignId,
                'siteId' => $siteId,
                'date' => $dateStr,
            ])
            ->one();

        if (!$record) {
            $record = new AnalyticsRecord();
            $record->campaignId = $campaignId;
            $record->siteId = $siteId;
            $record->date = $dateStr;
        }

        $record->totalRecipients = (int)$totalRecipients;
        $record->emailsSent = (int)$emailsSent;
        $record->smsSent = (int)$smsSent;
        $record->emailsOpened = (int)$emailsOpened;
        $record->smsOpened = (int)$smsOpened;
        $record->submissions = (int)$submissions;
        $record->expired = (int)$expired;

        $record->save(false);

        $this->logInfo('Refreshed statistics', [
            'campaignId' => $campaignId,
            'siteId' => $siteId,
            'date' => $dateStr,
        ]);
    }
}
