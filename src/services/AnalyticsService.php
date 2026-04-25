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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\LabelHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\AnalyticsRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\formieratingfield\fields\Rating;
use lindemannrock\formieratingfield\FormieRatingField;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Submission;

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
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
     */
    public function getDateRangeFromParam(string $dateRange): array
    {
        // Use centralized DateRangeHelper for full date range support
        // (today, yesterday, last7days, last30days, last90days, thisMonth, lastMonth, thisYear, lastYear, all)
        $bounds = DateRangeHelper::getBounds($dateRange);
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        $start = $bounds['start'] ? (clone $bounds['start'])->setTimezone($tz) : null;
        $end = $bounds['end'] ? (clone $bounds['end'])->setTimezone($tz)->modify('-1 day') : null;

        if (!$start && !$end) {
            $end = new \DateTime('now', $tz);
            $start = (clone $end)->modify('-30 days');
        } elseif (!$start) {
            $start = (clone $end)->modify('-30 days');
        } elseif (!$end) {
            $end = new \DateTime('now', $tz);
        }

        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Get overview statistics
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, int|float>
     */
    public function getOverviewStats(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange);

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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     */
    public function getDailyTrend(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange);
        $localDateExpr = DateFormatHelper::localDateExpression('campaignmanager_recipients.dateCreated');

        // Get daily counts
        $data = (clone $query)
            ->select([
                'date' => $localDateExpr,
                'COUNT(*) as recipients',
                'SUM(CASE WHEN emailSendDate IS NOT NULL THEN 1 ELSE 0 END) as emailsSent',
                'SUM(CASE WHEN smsSendDate IS NOT NULL THEN 1 ELSE 0 END) as smsSent',
                'SUM(CASE WHEN submissionId IS NOT NULL THEN 1 ELSE 0 END) as submissions',
            ])
            ->groupBy($localDateExpr)
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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     */
    public function getChannelDistribution(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange);

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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     */
    public function getEngagementOverTime(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange);
        $emailOpenExpr = DateFormatHelper::localDateExpression('emailOpenDate');
        $smsOpenExpr = DateFormatHelper::localDateExpression('smsOpenDate');

        // Get daily opens
        $emailOpens = (clone $query)
            ->select([
                'date' => $emailOpenExpr,
                'COUNT(*) as count',
            ])
            ->andWhere(['not', ['emailOpenDate' => null]])
            ->groupBy($emailOpenExpr)
            ->indexBy('date')
            ->column();

        $smsOpens = (clone $query)
            ->select([
                'date' => $smsOpenExpr,
                'COUNT(*) as count',
            ])
            ->andWhere(['not', ['smsOpenDate' => null]])
            ->groupBy($smsOpenExpr)
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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     */
    public function getConversionFunnel(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange);

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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array<int, array<string, mixed>>
     */
    public function getCampaignBreakdown(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
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
            $query = $this->buildRecipientQuery($campaign->id, $campaign->siteId, $dateRange);

            $totalRecipients = (clone $query)->count();
            $submissions = (clone $query)
                ->andWhere(['not', ['submissionId' => null]])
                ->count();

            $result[] = [
                'campaignId' => $campaign->id,
                'campaignName' => $campaign->title,
                'siteId' => $campaign->siteId,
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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @return array<int, array{value: int|string, label: string}>
     */
    public function getCampaignOptions(int|string|array $siteId): array
    {
        $campaignQuery = Campaign::find()->status(null)->unique();
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
     */
    public function getCampaignDailyTrend(int $campaignId, ?int $siteId, string $dateRange): array
    {
        $dates = $this->getDateRangeFromParam($dateRange);
        $query = $this->buildRecipientQuery($campaignId, $siteId ?? 'all', $dateRange);
        $recipientTable = RecipientRecord::tableName();
        // localDateExpression() wraps the column in [[...]]; pass the raw table.column
        // (without {{%...}} prefix) so Yii's quoting resolves cleanly.
        $smsSendExpr = DateFormatHelper::localDateExpression('campaignmanager_recipients.smsSendDate');
        $emailSendExpr = DateFormatHelper::localDateExpression('campaignmanager_recipients.emailSendDate');
        $smsOpenExpr = DateFormatHelper::localDateExpression('campaignmanager_recipients.smsOpenDate');
        $emailOpenExpr = DateFormatHelper::localDateExpression('campaignmanager_recipients.emailOpenDate');
        $submissionExpr = DateFormatHelper::localDateExpression('fs.dateCreated');

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
            ->select(['date' => $smsSendExpr, 'COUNT(*) as count'])
            ->andWhere(['not', [$recipientTable . '.smsSendDate' => null]])
            ->groupBy($smsSendExpr)
            ->all();

        foreach ($smsSentByDate as $row) {
            if (isset($sentData[$row['date']])) {
                $sentData[$row['date']] += (int)$row['count'];
            }
        }

        // Get email sent by date
        $emailSentByDate = (clone $query)
            ->select(['date' => $emailSendExpr, 'COUNT(*) as count'])
            ->andWhere(['not', [$recipientTable . '.emailSendDate' => null]])
            ->groupBy($emailSendExpr)
            ->all();

        foreach ($emailSentByDate as $row) {
            if (isset($sentData[$row['date']])) {
                $sentData[$row['date']] += (int)$row['count'];
            }
        }

        // Get opened by date (SMS)
        $smsOpenedByDate = (clone $query)
            ->select(['date' => $smsOpenExpr, 'COUNT(*) as count'])
            ->andWhere(['not', [$recipientTable . '.smsOpenDate' => null]])
            ->groupBy($smsOpenExpr)
            ->all();

        foreach ($smsOpenedByDate as $row) {
            if (isset($openedData[$row['date']])) {
                $openedData[$row['date']] += (int)$row['count'];
            }
        }

        // Get opened by date (Email)
        $emailOpenedByDate = (clone $query)
            ->select(['date' => $emailOpenExpr, 'COUNT(*) as count'])
            ->andWhere(['not', [$recipientTable . '.emailOpenDate' => null]])
            ->groupBy($emailOpenExpr)
            ->all();

        foreach ($emailOpenedByDate as $row) {
            if (isset($openedData[$row['date']])) {
                $openedData[$row['date']] += (int)$row['count'];
            }
        }

        // Get submissions - bucket by the Formie submission's dateCreated.
        // Always in sent mode: add the leftJoin here for this sub-query only.
        $submissionsQuery = (clone $query)
            ->andWhere(['not', [$recipientTable . '.submissionId' => null]])
            ->leftJoin(
                ['fs' => '{{%formie_submissions}}'],
                'fs.id = ' . $recipientTable . '.submissionId'
            );

        $submissionsByDate = $submissionsQuery
            ->select(['date' => $submissionExpr, 'COUNT(*) as count'])
            ->groupBy($submissionExpr)
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
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' (filter by recipient dateCreated) or 'response' (filter by submission dateCreated via JOIN)
     * @return Query
     */
    private function buildRecipientQuery(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent'): Query
    {
        $recipientTable = RecipientRecord::tableName();

        $query = (new Query())
            ->from([$recipientTable]);

        if ($dateBasis === 'response') {
            // JOIN formie_submissions so we can filter and bucket by submission dateCreated
            $query->innerJoin(
                ['fs' => '{{%formie_submissions}}'],
                'fs.id = ' . $recipientTable . '.submissionId'
            );
            $bounds = DateRangeHelper::getBounds($dateRange);
            if ($bounds['start']) {
                $query->andWhere(['>=', 'fs.dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['start'])]);
            }
            if ($bounds['end']) {
                $query->andWhere(['<', 'fs.dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['end'])]);
            }
        } else {
            $bounds = DateRangeHelper::getBounds($dateRange);
            if ($bounds['start']) {
                $query->andWhere(['>=', $recipientTable . '.dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['start'])]);
            }
            if ($bounds['end']) {
                $query->andWhere(['<', $recipientTable . '.dateCreated', \craft\helpers\Db::prepareDateForDb($bounds['end'])]);
            }
        }

        if ($campaignId !== 'all') {
            $query->andWhere([$recipientTable . '.campaignId' => $campaignId]);
        }

        if ($siteId !== 'all') {
            $query->andWhere([$recipientTable . '.siteId' => $siteId]);
        }

        return $query;
    }

    /**
     * Normalize a siteId filter into a flat list of site IDs.
     *
     * @param int|string|array<int> $siteId
     * @return array<int>
     */
    private function _resolveSiteIdList(int|string|array $siteId): array
    {
        if (is_array($siteId)) {
            return array_values(array_map('intval', $siteId));
        }
        if ($siteId === 'all' || $siteId === '') {
            return Craft::$app->getSites()->getEditableSiteIds();
        }

        return [(int)$siteId];
    }

    /**
     * Build a local-date SQL expression for the given column.
     *
     * @param string $column
     * @return \yii\db\Expression
     */
    /**
     * Get rating fields present on forms tied to submissions in the current filter scope.
     *
     * Returns deduplicated entries (by fieldId) across all campaigns in scope.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @return array<int, array{fieldId: int, handle: string, label: string, shortLabel: string, formId: int, formTitle: string}>
     * @since 5.8.0
     */
    public function getRatingFieldsInScope(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent'): array
    {
        if (!PluginHelper::isPluginEnabled('formie-rating-field')) {
            return [];
        }

        if (!class_exists(Rating::class)) {
            return [];
        }

        // Iterate sites explicitly so we get one row per (campaign, site).
        // Craft's element query defaults to "unique by element ID" when id() is set,
        // which would hide site-localized duplicates of a single campaign.
        $siteIds = $this->_resolveSiteIdList($siteId);
        $campaigns = [];
        foreach ($siteIds as $sid) {
            $q = Campaign::find()->status(null)->siteId($sid);
            if ($campaignId !== 'all') {
                $q->id($campaignId);
            }
            foreach ($q->all() as $c) {
                $campaigns[] = $c;
            }
        }

        $fields = [];
        $seenFieldIds = [];

        foreach ($campaigns as $campaign) {
            if (!$campaign->formId) {
                continue;
            }

            $formElement = \verbb\formie\elements\Form::find()->id($campaign->formId)->one();
            if (!($formElement instanceof \verbb\formie\elements\Form)) {
                continue;
            }

            foreach ($formElement->getFields() as $field) {
                if (!($field instanceof Rating)) {
                    continue;
                }

                if (in_array($field->id, $seenFieldIds, true)) {
                    continue;
                }

                $seenFieldIds[] = $field->id;
                $fields[] = [
                    'fieldId' => $field->id,
                    'handle' => $field->handle,
                    'label' => $field->label,
                    'shortLabel' => LabelHelper::shorten($field->label),
                    'formId' => $formElement->id,
                    'formTitle' => $formElement->title,
                ];
            }
        }

        return $fields;
    }

    /**
     * Calculate rating stats for a specific field across the filter scope.
     *
     * Returns the full stats array from calculateStatsForSubmissions(). Keys vary by
     * field type: NPS fields include npsScore/promoters/passives/detractors/average;
     * Star/Emoji fields include average/distribution/median/mode.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int|null $fieldId Formie field ID; if null, first rating field in scope is used
     * @return array<string, mixed>
     * @since 5.8.0
     */
    public function getRatingStats(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent', ?int $fieldId = null): array
    {
        $zeroState = [
            'fieldType' => null,
            'fieldLabel' => '',
            'fieldHandle' => '',
            'totalResponses' => 0,
            'minValue' => 0,
            'maxValue' => 10,
            'npsScore' => null,
            'promoters' => 0,
            'promotersPercentage' => 0,
            'passives' => 0,
            'passivesPercentage' => 0,
            'detractors' => 0,
            'detractorsPercentage' => 0,
            'average' => 0,
        ];

        if (!PluginHelper::isPluginEnabled('formie-rating-field')) {
            return $zeroState;
        }

        if (!class_exists(Rating::class)) {
            return $zeroState;
        }

        $ratingFields = $this->getRatingFieldsInScope($campaignId, $siteId, $dateRange, $dateBasis);
        if (empty($ratingFields)) {
            return $zeroState;
        }

        // Pick the target field
        $targetFieldInfo = null;
        if ($fieldId !== null) {
            foreach ($ratingFields as $rf) {
                if ($rf['fieldId'] === $fieldId) {
                    $targetFieldInfo = $rf;
                    break;
                }
            }
        }
        if ($targetFieldInfo === null) {
            $targetFieldInfo = $ratingFields[0];
        }

        $formElement = \verbb\formie\elements\Form::find()->id($targetFieldInfo['formId'])->one();
        if (!($formElement instanceof \verbb\formie\elements\Form)) {
            return $zeroState;
        }

        /** @var Rating|null $ratingField */
        $ratingField = null;
        foreach ($formElement->getFields() as $f) {
            if ($f instanceof Rating && $f->id === $targetFieldInfo['fieldId']) {
                $ratingField = $f;
                break;
            }
        }
        if (!$ratingField) {
            return $zeroState;
        }

        $submissions = $this->_getSubmissionsInScope($campaignId, $siteId, $dateRange, $dateBasis, $targetFieldInfo['formId']);
        if (empty($submissions)) {
            return FormieRatingField::$plugin->statistics->calculateStatsForSubmissions([], $ratingField);
        }

        return FormieRatingField::$plugin->statistics->calculateStatsForSubmissions($submissions, $ratingField);
    }


    /**
     * Per-campaign rating breakdown — one row per campaign in scope.
     *
     * Returns a shape of {fieldType: string, rows: array} so the template and export
     * can branch on field type without re-querying the field.
     *
     * NPS rows:        campaignId, campaignName, siteId, totalResponses, npsScore, promoters, passives, detractors
     * Star/Emoji rows: campaignId, campaignName, siteId, totalResponses, average, median, mode
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int|null $fieldId Required; returns empty result if null
     * @return array{fieldType: string|null, rows: array<int, array<string, mixed>>}
     * @since 5.8.0
     */
    public function getCampaignRatingBreakdown(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent', ?int $fieldId = null): array
    {
        $emptyResult = ['fieldType' => null, 'rows' => []];

        if (!PluginHelper::isPluginEnabled('formie-rating-field') || $fieldId === null) {
            return $emptyResult;
        }

        if (!class_exists(Rating::class)) {
            return $emptyResult;
        }

        // Iterate sites explicitly so we get one row per (campaign, site) — see
        // matching note in getRatingFieldsInScope().
        $siteIds = $this->_resolveSiteIdList($siteId);
        $campaigns = [];
        foreach ($siteIds as $sid) {
            $q = Campaign::find()->status(null)->siteId($sid);
            if ($campaignId !== 'all') {
                $q->id($campaignId);
            }
            foreach ($q->all() as $c) {
                $campaigns[] = $c;
            }
        }

        $rows = [];
        $fieldType = null;

        foreach ($campaigns as $campaign) {
            if (!$campaign->formId) {
                $rows[] = $this->_emptyBreakdownRow($campaign, null);
                continue;
            }

            $formElement = \verbb\formie\elements\Form::find()->id($campaign->formId)->one();
            if (!($formElement instanceof \verbb\formie\elements\Form)) {
                $rows[] = $this->_emptyBreakdownRow($campaign, null);
                continue;
            }

            /** @var Rating|null $ratingField */
            $ratingField = null;
            foreach ($formElement->getFields() as $f) {
                if ($f instanceof Rating && $f->id === $fieldId) {
                    $ratingField = $f;
                    break;
                }
            }

            if (!$ratingField) {
                $rows[] = $this->_emptyBreakdownRow($campaign, null);
                continue;
            }

            // Capture field type from the first found field (all rows share the same field)
            if ($fieldType === null) {
                $fieldType = $ratingField->ratingType;
            }

            $submissions = $this->_getSubmissionsInScope($campaign->id, $campaign->siteId, $dateRange, $dateBasis, $campaign->formId);
            $stats = FormieRatingField::$plugin->statistics->calculateStatsForSubmissions($submissions, $ratingField);

            if ($stats['fieldType'] === Rating::RATING_TYPE_NPS) {
                $rows[] = [
                    'campaignId' => $campaign->id,
                    'campaignName' => $campaign->title,
                    'siteId' => $campaign->siteId,
                    'totalResponses' => $stats['totalResponses'],
                    'npsScore' => $stats['totalResponses'] > 0 ? ($stats['npsScore'] ?? null) : null,
                    'promoters' => $stats['promoters'] ?? 0,
                    'passives' => $stats['passives'] ?? 0,
                    'detractors' => $stats['detractors'] ?? 0,
                ];
            } else {
                $rows[] = [
                    'campaignId' => $campaign->id,
                    'campaignName' => $campaign->title,
                    'siteId' => $campaign->siteId,
                    'totalResponses' => $stats['totalResponses'],
                    'average' => $stats['average'] ?? 0,
                    'median' => $stats['median'] ?? 0,
                    'mode' => $stats['mode'] ?? null,
                ];
            }
        }

        usort($rows, fn($a, $b) => $b['totalResponses'] <=> $a['totalResponses']);

        return ['fieldType' => $fieldType, 'rows' => $rows];
    }

    /**
     * Build an empty breakdown row for a campaign (no form or no matching field).
     *
     * @param Campaign $campaign
     * @param string|null $fieldType
     * @return array<string, mixed>
     */
    private function _emptyBreakdownRow(Campaign $campaign, ?string $fieldType): array
    {
        if ($fieldType === Rating::RATING_TYPE_NPS || $fieldType === null) {
            return [
                'campaignId' => $campaign->id,
                'campaignName' => $campaign->title,
                'siteId' => $campaign->siteId,
                'totalResponses' => 0,
                'npsScore' => null,
                'promoters' => 0,
                'passives' => 0,
                'detractors' => 0,
            ];
        }

        return [
            'campaignId' => $campaign->id,
            'campaignName' => $campaign->title,
            'siteId' => $campaign->siteId,
            'totalResponses' => 0,
            'average' => 0,
            'median' => 0,
            'mode' => null,
        ];
    }

    /**
     * Rating trend over time — bucketed by day (or week if > 90 days).
     *
     * NPS fields return: {labels, value (npsScore per bucket), promoters, passives, detractors,
     *                     scaleMin: -100, scaleMax: 100, chartType: 'line'}
     * Star/Emoji fields return: {labels, value (average per bucket), scaleMin, scaleMax, chartType: 'line'}
     *
     * The JS uses `value` (neutral name), `scaleMin`/`scaleMax` for y-axis, and `chartType` for
     * chart type selection.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int|null $fieldId Formie field ID
     * @return array<string, mixed>
     * @since 5.8.0
     */
    public function getRatingTrend(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent', ?int $fieldId = null): array
    {
        $empty = ['labels' => [], 'value' => [], 'scaleMin' => -100, 'scaleMax' => 100, 'chartType' => 'line'];

        if (!PluginHelper::isPluginEnabled('formie-rating-field')) {
            return $empty;
        }

        if (!class_exists(Rating::class)) {
            return $empty;
        }

        $stats = $this->getRatingStats($campaignId, $siteId, $dateRange, $dateBasis, $fieldId);
        if ($stats['totalResponses'] === 0) {
            return $empty;
        }

        // Resolve field info
        $ratingFields = $this->getRatingFieldsInScope($campaignId, $siteId, $dateRange, $dateBasis);
        $targetFieldInfo = null;
        if ($fieldId !== null) {
            foreach ($ratingFields as $rf) {
                if ($rf['fieldId'] === $fieldId) {
                    $targetFieldInfo = $rf;
                    break;
                }
            }
        }
        if ($targetFieldInfo === null && !empty($ratingFields)) {
            $targetFieldInfo = $ratingFields[0];
        }
        if ($targetFieldInfo === null) {
            return $empty;
        }

        $formElement = \verbb\formie\elements\Form::find()->id($targetFieldInfo['formId'])->one();
        if (!($formElement instanceof \verbb\formie\elements\Form)) {
            return $empty;
        }

        /** @var Rating|null $ratingField */
        $ratingField = null;
        foreach ($formElement->getFields() as $f) {
            if ($f instanceof Rating && $f->id === $targetFieldInfo['fieldId']) {
                $ratingField = $f;
                break;
            }
        }
        if (!$ratingField) {
            return $empty;
        }

        $isNps = $ratingField->ratingType === Rating::RATING_TYPE_NPS;

        $dates = $this->getDateRangeFromParam($dateRange);
        $start = $dates['start'];
        $end = $dates['end'];

        // Determine bucket size
        $daysDiff = (int)$start->diff($end)->days;
        $byWeek = $daysDiff > 90;

        // Fetch all submissions in scope
        $allSubmissions = $this->_getSubmissionsInScope($campaignId, $siteId, $dateRange, $dateBasis, $targetFieldInfo['formId']);

        // Index submissions by date string
        $submissionsByDate = [];
        foreach ($allSubmissions as $sub) {
            $submissionDate = new \DateTime($sub->dateCreated->format('Y-m-d'));
            $dateKey = $byWeek
                ? $submissionDate->format('o-W') // ISO year-week
                : $submissionDate->format('Y-m-d');
            if (!isset($submissionsByDate[$dateKey])) {
                $submissionsByDate[$dateKey] = [];
            }
            $submissionsByDate[$dateKey][] = $sub;
        }

        $labels = [];
        $values = [];
        $promotersData = [];
        $passivesData = [];
        $detractorsData = [];

        if ($byWeek) {
            // Build weekly buckets
            $cursor = clone $start;
            // Align to start of week (Monday)
            $dayOfWeek = (int)$cursor->format('N');
            if ($dayOfWeek > 1) {
                $cursor->modify('-' . ($dayOfWeek - 1) . ' days');
            }
            while ($cursor <= $end) {
                $weekKey = $cursor->format('o-W');
                $weekLabel = $cursor->format('M j');
                $bucketSubs = $submissionsByDate[$weekKey] ?? [];
                $bucketStats = FormieRatingField::$plugin->statistics->calculateStatsForSubmissions($bucketSubs, $ratingField);

                $labels[] = $weekLabel;
                if ($isNps) {
                    $values[] = $bucketStats['totalResponses'] > 0 ? ($bucketStats['npsScore'] ?? null) : null;
                    $promotersData[] = $bucketStats['promoters'] ?? 0;
                    $passivesData[] = $bucketStats['passives'] ?? 0;
                    $detractorsData[] = $bucketStats['detractors'] ?? 0;
                } else {
                    $values[] = $bucketStats['totalResponses'] > 0 ? ($bucketStats['average'] ?? null) : null;
                }

                $cursor->modify('+7 days');
            }
        } else {
            $cursor = clone $start;
            while ($cursor <= $end) {
                $dateKey = $cursor->format('Y-m-d');
                $bucketSubs = $submissionsByDate[$dateKey] ?? [];
                $bucketStats = FormieRatingField::$plugin->statistics->calculateStatsForSubmissions($bucketSubs, $ratingField);

                $labels[] = $cursor->format('M j');
                if ($isNps) {
                    $values[] = $bucketStats['totalResponses'] > 0 ? ($bucketStats['npsScore'] ?? null) : null;
                    $promotersData[] = $bucketStats['promoters'] ?? 0;
                    $passivesData[] = $bucketStats['passives'] ?? 0;
                    $detractorsData[] = $bucketStats['detractors'] ?? 0;
                } else {
                    $values[] = $bucketStats['totalResponses'] > 0 ? ($bucketStats['average'] ?? null) : null;
                }

                $cursor->modify('+1 day');
            }
        }

        if ($isNps) {
            return [
                'labels' => $labels,
                'value' => $values,
                'promoters' => $promotersData,
                'passives' => $passivesData,
                'detractors' => $detractorsData,
                'scaleMin' => -100,
                'scaleMax' => 100,
                'chartType' => 'line',
            ];
        }

        return [
            'labels' => $labels,
            'value' => $values,
            'scaleMin' => (int)$ratingField->minValue,
            'scaleMax' => (int)$ratingField->maxValue,
            'chartType' => 'line',
        ];
    }

    /**
     * Rating distribution — chart data.
     *
     * NPS fields:        {labels: [Promoters, Passives, Detractors], data: [int, int, int], chartType: 'doughnut'}
     * Star/Emoji fields: {labels: ['1','2',...,'N'], data: [int, ...], chartType: 'bar'}
     *                    All integer buckets from minValue to maxValue are included (zeros for missing).
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int|null $fieldId Formie field ID
     * @return array{labels: array<string>, data: array<int>, chartType: string}
     * @since 5.8.0
     */
    public function getRatingDistribution(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent', ?int $fieldId = null): array
    {
        $empty = ['labels' => [], 'data' => [], 'chartType' => 'doughnut'];

        if (!PluginHelper::isPluginEnabled('formie-rating-field')) {
            return $empty;
        }

        if (!class_exists(Rating::class)) {
            return $empty;
        }

        $stats = $this->getRatingStats($campaignId, $siteId, $dateRange, $dateBasis, $fieldId);
        if ($stats['totalResponses'] === 0) {
            return $empty;
        }

        if ($stats['fieldType'] === Rating::RATING_TYPE_NPS) {
            return [
                'labels' => [
                    Craft::t('campaign-manager', 'Promoters'),
                    Craft::t('campaign-manager', 'Passives'),
                    Craft::t('campaign-manager', 'Detractors'),
                ],
                'data' => [
                    (int)($stats['promoters'] ?? 0),
                    (int)($stats['passives'] ?? 0),
                    (int)($stats['detractors'] ?? 0),
                ],
                'chartType' => 'doughnut',
            ];
        }

        // Star/Emoji: build a full integer range from minValue to maxValue,
        // filling missing buckets with 0 so every possible value appears in the chart.
        $distribution = $stats['distribution'] ?? [];
        $min = (int)($stats['minValue'] ?? 1);
        $max = (int)($stats['maxValue'] ?? 5);

        $labels = [];
        $data = [];
        for ($v = $min; $v <= $max; $v++) {
            $labels[] = (string)$v;
            $data[] = (int)($distribution[$v] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data, 'chartType' => 'bar'];
    }

    /**
     * Fetch Formie Submission elements in scope for a specific form ID.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int $formId The Formie form ID to filter submissions by
     * @return Submission[]
     */
    private function _getSubmissionsInScope(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis, int $formId): array
    {
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange, $dateBasis);

        $submissionIds = (clone $query)
            ->select(['submissionId'])
            ->andWhere(['not', ['submissionId' => null]])
            ->column();

        if (empty($submissionIds)) {
            return [];
        }

        /** @var Submission[] $submissions */
        $submissions = Submission::find()
            ->id($submissionIds)
            ->formId($formId)
            ->isIncomplete(false)
            ->isSpam(false)
            ->all();

        return $submissions;
    }

    /**
     * Build the Summary section export rows for the ratings export.
     *
     * Returns a single row with aggregate stats across the entire scope for the chosen field.
     *
     * @param array<int, array{fieldId: int, handle: string, label: string, shortLabel: string, formId: int, formTitle: string}> $ratingFields
     * @param array{fieldId: int, handle: string, label: string, shortLabel: string, formId: int, formTitle: string} $ratingFieldInfo
     * @param array<string, mixed> $stats Output of getRatingStats()
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @return array{headers: array<string>, rows: array<array<mixed>>}
     * @since 5.8.0
     */
    public function buildSummaryExportRow(array $ratingFields, array $ratingFieldInfo, array $stats, string $dateRange, string $dateBasis): array
    {
        $isNps = ($stats['fieldType'] ?? null) === Rating::RATING_TYPE_NPS;

        $dateBasisLabel = $dateBasis === 'response'
            ? Craft::t('campaign-manager', 'Response date')
            : Craft::t('campaign-manager', 'Send activity');

        $headers = [
            Craft::t('campaign-manager', 'Rating Field'),
            Craft::t('campaign-manager', 'Field Type'),
            Craft::t('campaign-manager', 'Date Basis'),
            Craft::t('campaign-manager', 'Date Range'),
            Craft::t('campaign-manager', 'Total Responses'),
            Craft::t('campaign-manager', 'NPS Score'),
            Craft::t('campaign-manager', 'Promoters'),
            Craft::t('campaign-manager', 'Promoters %'),
            Craft::t('campaign-manager', 'Passives'),
            Craft::t('campaign-manager', 'Passives %'),
            Craft::t('campaign-manager', 'Detractors'),
            Craft::t('campaign-manager', 'Detractors %'),
            Craft::t('campaign-manager', 'Average'),
            Craft::t('campaign-manager', 'Median'),
            Craft::t('campaign-manager', 'Most Common'),
        ];

        $totalResponses = $stats['totalResponses'] ?? 0;

        if ($isNps) {
            $row = [
                $ratingFieldInfo['label'],
                Craft::t('campaign-manager', 'NPS'),
                $dateBasisLabel,
                $dateRange,
                $totalResponses,
                $totalResponses > 0 ? ($stats['npsScore'] ?? '—') : '—',
                $totalResponses > 0 ? ($stats['promoters'] ?? 0) : '—',
                $totalResponses > 0 ? (($stats['promotersPercentage'] ?? 0) . '%') : '—',
                $totalResponses > 0 ? ($stats['passives'] ?? 0) : '—',
                $totalResponses > 0 ? (($stats['passivesPercentage'] ?? 0) . '%') : '—',
                $totalResponses > 0 ? ($stats['detractors'] ?? 0) : '—',
                $totalResponses > 0 ? (($stats['detractorsPercentage'] ?? 0) . '%') : '—',
                $totalResponses > 0 ? round((float)($stats['average'] ?? 0), 2) : '—',
                '—',
                '—',
            ];
        } else {
            $row = [
                $ratingFieldInfo['label'],
                Craft::t('campaign-manager', 'Star Rating'),
                $dateBasisLabel,
                $dateRange,
                $totalResponses,
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                $totalResponses > 0 ? round((float)($stats['average'] ?? 0), 2) : '—',
                $totalResponses > 0 ? ($stats['median'] ?? '—') : '—',
                $totalResponses > 0 ? ($stats['mode'] ?? '—') : '—',
            ];
        }

        return ['headers' => $headers, 'rows' => [$row]];
    }

    /**
     * Build the Per Campaign section export rows from the breakdown result.
     *
     * @param array{fieldType: string|null, rows: array<int, array<string, mixed>>} $breakdownResult Output of getCampaignRatingBreakdown()
     * @return array{headers: array<string>, rows: array<array<mixed>>}
     * @since 5.8.0
     */
    public function buildCampaignBreakdownExportRows(array $breakdownResult): array
    {
        $fieldType = $breakdownResult['fieldType'];
        $breakdownRows = $breakdownResult['rows'];
        $isNps = $fieldType === Rating::RATING_TYPE_NPS;

        if ($isNps) {
            $headers = [
                Craft::t('campaign-manager', 'Campaign'),
                Craft::t('campaign-manager', 'Site'),
                Craft::t('campaign-manager', 'Responses'),
                Craft::t('campaign-manager', 'NPS Score'),
                Craft::t('campaign-manager', 'Promoters'),
                Craft::t('campaign-manager', 'Passives'),
                Craft::t('campaign-manager', 'Detractors'),
            ];
        } else {
            $headers = [
                Craft::t('campaign-manager', 'Campaign'),
                Craft::t('campaign-manager', 'Site'),
                Craft::t('campaign-manager', 'Responses'),
                Craft::t('campaign-manager', 'Average'),
                Craft::t('campaign-manager', 'Median'),
                Craft::t('campaign-manager', 'Most Common'),
            ];
        }

        $rows = [];
        foreach ($breakdownRows as $data) {
            $site = $data['siteId'] ? Craft::$app->getSites()->getSiteById($data['siteId']) : null;
            $hasResponses = $data['totalResponses'] > 0;

            if ($isNps) {
                $rows[] = [
                    $data['campaignName'],
                    $site?->name ?? '—',
                    $data['totalResponses'],
                    $hasResponses ? ($data['npsScore'] !== null ? $data['npsScore'] : '—') : '—',
                    $hasResponses ? $data['promoters'] : '—',
                    $hasResponses ? $data['passives'] : '—',
                    $hasResponses ? $data['detractors'] : '—',
                ];
            } else {
                $rows[] = [
                    $data['campaignName'],
                    $site?->name ?? '—',
                    $data['totalResponses'],
                    $hasResponses ? round((float)($data['average'] ?? 0), 2) : '—',
                    $hasResponses ? $data['median'] : '—',
                    $hasResponses ? ($data['mode'] ?? '—') : '—',
                ];
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Get raw rating responses — one row per recipient who submitted a rating.
     *
     * Scoped by all filter parameters. Bulk-fetches submissions by ID to avoid N+1.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @param int|null $fieldId Rating field ID; required, returns empty if null
     * @return array{headers: array<string>, rows: array<array<mixed>>}
     * @since 5.8.0
     */
    public function getRawRatingResponses(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent', ?int $fieldId = null): array
    {
        $headers = [
            Craft::t('campaign-manager', 'Campaign'),
            Craft::t('campaign-manager', 'Site'),
            Craft::t('campaign-manager', 'Recipient'),
            Craft::t('campaign-manager', 'Email'),
            Craft::t('campaign-manager', 'Phone'),
            Craft::t('campaign-manager', 'Send Date'),
            Craft::t('campaign-manager', 'Response Date'),
            Craft::t('campaign-manager', 'Rating Value'),
        ];

        $emptyResult = ['headers' => $headers, 'rows' => []];

        if (!PluginHelper::isPluginEnabled('formie-rating-field') || $fieldId === null) {
            return $emptyResult;
        }

        if (!class_exists(Rating::class)) {
            return $emptyResult;
        }

        $recipientTable = RecipientRecord::tableName();

        // Build recipient query scoped by filters — must have a submission
        $query = $this->buildRecipientQuery($campaignId, $siteId, $dateRange, $dateBasis);
        $query->andWhere(['not', [$recipientTable . '.submissionId' => null]]);

        $recipientRows = $query
            ->select([
                $recipientTable . '.campaignId',
                $recipientTable . '.siteId',
                $recipientTable . '.name',
                $recipientTable . '.email',
                $recipientTable . '.sms',
                $recipientTable . '.emailSendDate',
                $recipientTable . '.smsSendDate',
                $recipientTable . '.submissionId',
            ])
            ->orderBy([$recipientTable . '.dateCreated' => SORT_ASC])
            ->all();

        if (empty($recipientRows)) {
            return $emptyResult;
        }

        // Bulk-fetch submissions to avoid N+1
        $submissionIds = array_unique(array_column($recipientRows, 'submissionId'));

        /** @var Submission[] $submissions */
        $submissions = Submission::find()
            ->id($submissionIds)
            ->isIncomplete(false)
            ->isSpam(false)
            ->all();

        // Index by ID for fast lookup
        $submissionMap = [];
        foreach ($submissions as $sub) {
            $submissionMap[$sub->id] = $sub;
        }

        // Resolve field handle — we need it to read the field value from the submission
        $ratingFields = $this->getRatingFieldsInScope($campaignId, $siteId, $dateRange, $dateBasis);
        $fieldHandle = null;
        foreach ($ratingFields as $rf) {
            if ($rf['fieldId'] === $fieldId) {
                $fieldHandle = $rf['handle'];
                break;
            }
        }

        if ($fieldHandle === null) {
            return $emptyResult;
        }

        // Build export rows
        $rows = [];
        foreach ($recipientRows as $data) {
            $submissionId = (int)$data['submissionId'];
            $submission = $submissionMap[$submissionId] ?? null;

            if (!$submission) {
                continue;
            }

            $site = $data['siteId'] ? Craft::$app->getSites()->getSiteById((int)$data['siteId']) : null;
            $campaign = Craft::$app->elements->getElementById((int)$data['campaignId'], null, (int)$data['siteId'] ?: null);

            // Determine send date: prefer email send date, fall back to SMS send date
            $sendDate = $data['emailSendDate'] ?? $data['smsSendDate'];
            $sendDateFormatted = $sendDate ? (new \DateTime($sendDate))->format('Y-m-d H:i:s') : '—';

            $responseDate = $submission->dateCreated?->format('Y-m-d H:i:s') ?? '—';

            try {
                $ratingValue = $submission->getFieldValue($fieldHandle);
            } catch (\Throwable) {
                $ratingValue = '—';
            }

            $rows[] = [
                $campaign instanceof \lindemannrock\campaignmanager\elements\Campaign ? ($campaign->title ?? '—') : '—',
                $site?->name ?? '—',
                $data['name'] ?? '—',
                $data['email'] ?? '—',
                $data['sms'] ?? '—',
                $sendDateFormatted,
                $responseDate,
                $ratingValue !== null && $ratingValue !== '' ? (string)$ratingValue : '—',
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Refresh statistics for a campaign
     *
     * Recalculates and stores aggregated statistics.
     *
     * @param int $campaignId Campaign ID
     * @param int $siteId Site ID
     * @param \DateTime|null $date Specific date to refresh, or null for today
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
