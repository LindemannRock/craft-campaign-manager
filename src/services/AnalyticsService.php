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
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\LabelHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\helpers\SiteAccessHelper;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\formieratingfield\fields\Rating;
use lindemannrock\formieratingfield\FormieRatingField;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Submission;
use yii\db\Expression;

/**
 * Analytics Service
 *
 * Provides campaign analytics and statistics.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.2.0
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
     * Alias of the recipients table inside analytics queries, so columns stay
     * qualified whatever table prefix the install uses.
     */
    private const RECIPIENTS = 'recipients';

    /**
     * Alias of the Formie submissions table joined for response dates.
     */
    private const SUBMISSIONS = 'fs';

    /**
     * Get the first and last local calendar day a date range covers, for
     * building chart buckets.
     *
     * "All time" reaches back to the oldest retained recipient event in the
     * campaign and site scope instead of a fixed trailing window; without any
     * event it covers today only.
     *
     * @param string $dateRange Date range parameter
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @return array{start: \DateTime, end: \DateTime}
     */
    public function getDateRangeFromParam(string $dateRange, int|string $campaignId = 'all', int|string|array $siteId = 'all'): array
    {
        $bounds = DateRangeHelper::getBounds($dateRange);
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        $start = $bounds['start'] ? (clone $bounds['start'])->setTimezone($tz) : null;
        // The end bound is exclusive, so the last covered day is the one before it.
        $end = $bounds['end'] ? (clone $bounds['end'])->setTimezone($tz)->modify('-1 day') : new \DateTime('now', $tz);

        if (!$start) {
            $earliest = $this->earliestEventDate($campaignId, $siteId);
            $start = $earliest ? $earliest->setTimezone($tz) : clone $end;
        }

        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);

        if ($start > $end) {
            $start = clone $end;
        }

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
        $bounds = DateRangeHelper::getBounds($dateRange);
        $query = $this->joinSubmissions($this->scopeQuery($campaignId, $siteId));
        $this->whereAnyEventInRange($query, $bounds);

        // Every figure counts its own event inside the range, all in one
        // aggregate query instead of one COUNT(*) round-trip per figure.
        $row = $query
            ->select($this->metricSelect($query, $bounds))
            ->one();

        $totalRecipients = (int)($row['totalRecipients'] ?? 0);
        $emailsSent = (int)($row['emailsSent'] ?? 0);
        $smsSent = (int)($row['smsSent'] ?? 0);
        $emailsOpened = (int)($row['emailsOpened'] ?? 0);
        $smsOpened = (int)($row['smsOpened'] ?? 0);
        $uniqueInvited = (int)($row['uniqueInvited'] ?? 0);
        $uniqueOpened = (int)($row['uniqueOpened'] ?? 0);
        $submissions = (int)($row['submissions'] ?? 0);
        $expired = (int)($row['expired'] ?? 0);

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
            'uniqueInvited' => $uniqueInvited,
            'uniqueOpened' => $uniqueOpened,
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
        $dates = $this->getDateRangeFromParam($dateRange, $campaignId, $siteId);
        $bounds = DateRangeHelper::getBounds($dateRange);
        $scope = $this->scopeQuery($campaignId, $siteId);

        // Each series is bucketed by the day its own event happened.
        $series = [
            'recipients' => $this->dailyCounts($scope, self::RECIPIENTS . '.dateCreated', $bounds),
            'emailsSent' => $this->dailyCounts($scope, self::RECIPIENTS . '.emailSendDate', $bounds),
            'smsSent' => $this->dailyCounts($scope, self::RECIPIENTS . '.smsSendDate', $bounds),
            'submissions' => $this->dailyCounts($this->joinSubmissions($scope), self::SUBMISSIONS . '.dateCreated', $bounds),
        ];

        $labels = [];
        $values = array_fill_keys(array_keys($series), []);
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            foreach ($series as $name => $counts) {
                $values[$name][] = $counts[$dateStr] ?? 0;
            }
            $date->modify('+1 day');
        }

        return ['labels' => $labels] + $values;
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
        $bounds = DateRangeHelper::getBounds($dateRange);
        $emailSent = $this->eventInRange(self::RECIPIENTS . '.emailSendDate', $bounds);
        $smsSent = $this->eventInRange(self::RECIPIENTS . '.smsSendDate', $bounds);

        // Recipients invited inside the range, split by the channels used; one
        // aggregate query for the three exclusive buckets.
        $query = $this->scopeQuery($campaignId, $siteId)
            ->andWhere(['or', $emailSent, $smsSent]);
        $row = $query
            ->select([
                'emailOnly' => $this->countWhere($query, ['and', $emailSent, ['not', $smsSent]]),
                'smsOnly' => $this->countWhere($query, ['and', $smsSent, ['not', $emailSent]]),
                'both' => $this->countWhere($query, ['and', $emailSent, $smsSent]),
            ])
            ->one();

        return [
            'labels' => [
                Craft::t('campaign-manager', 'Email Only'),
                Craft::t('campaign-manager', 'SMS Only'),
                Craft::t('campaign-manager', 'Both'),
            ],
            'values' => [
                (int)($row['emailOnly'] ?? 0),
                (int)($row['smsOnly'] ?? 0),
                (int)($row['both'] ?? 0),
            ],
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
        $dates = $this->getDateRangeFromParam($dateRange, $campaignId, $siteId);
        $bounds = DateRangeHelper::getBounds($dateRange);
        $scope = $this->scopeQuery($campaignId, $siteId);

        $emailOpens = $this->dailyCounts($scope, self::RECIPIENTS . '.emailOpenDate', $bounds);
        $smsOpens = $this->dailyCounts($scope, self::RECIPIENTS . '.smsOpenDate', $bounds);

        $labels = [];
        $emailSeries = [];
        $smsSeries = [];
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $emailSeries[] = $emailOpens[$dateStr] ?? 0;
            $smsSeries[] = $smsOpens[$dateStr] ?? 0;
            $date->modify('+1 day');
        }

        return [
            'labels' => $labels,
            'emailOpens' => $emailSeries,
            'smsOpens' => $smsSeries,
        ];
    }

    /**
     * Get conversion funnel data.
     *
     * The funnel follows one cohort: the recipients added inside the date
     * range. As of the end of the range, it counts how many of them had been
     * sent an invitation on either channel, how many of those had opened
     * one, and how many of those had responded. Every stage is a subset of
     * the stage before it and every recipient counts once, so the stages
     * never exceed the cohort and never grow from one stage to the next.
     * Without a range end (all time) later events count too. Invitations,
     * opens and responses of recipients added before the range are activity
     * of the range, reported by the summary and the charts, not conversion
     * of this cohort.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @return array{labels: list<string>, values: list<int>}
     */
    public function getConversionFunnel(int|string $campaignId, int|string|array $siteId, string $dateRange): array
    {
        $bounds = DateRangeHelper::getBounds($dateRange);
        $asOfRangeEnd = ['start' => null, 'end' => $bounds['end']];

        $query = $this->joinSubmissions($this->scopeQuery($campaignId, $siteId))
            ->andWhere($this->eventInRange(self::RECIPIENTS . '.dateCreated', $bounds));

        $sent = [
            'or',
            $this->eventInRange(self::RECIPIENTS . '.emailSendDate', $asOfRangeEnd),
            $this->eventInRange(self::RECIPIENTS . '.smsSendDate', $asOfRangeEnd),
        ];
        $opened = [
            'and',
            $sent,
            [
                'or',
                $this->eventInRange(self::RECIPIENTS . '.emailOpenDate', $asOfRangeEnd),
                $this->eventInRange(self::RECIPIENTS . '.smsOpenDate', $asOfRangeEnd),
            ],
        ];
        $responded = [
            'and',
            $opened,
            $this->eventInRange(self::SUBMISSIONS . '.dateCreated', $asOfRangeEnd),
        ];

        $row = $query
            ->select([
                'added' => new Expression('COUNT(*)'),
                'sent' => $this->countWhere($query, $sent),
                'opened' => $this->countWhere($query, $opened),
                'responded' => $this->countWhere($query, $responded),
            ])
            ->one();

        return [
            'labels' => [
                Craft::t('campaign-manager', 'Recipients Added'),
                Craft::t('campaign-manager', 'Invitations Sent'),
                Craft::t('campaign-manager', 'Opened'),
                Craft::t('campaign-manager', 'Responded'),
            ],
            'values' => [
                (int)($row['added'] ?? 0),
                (int)($row['sent'] ?? 0),
                (int)($row['opened'] ?? 0),
                (int)($row['responded'] ?? 0),
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
        // An empty site list means no site. Craft would read [] as the current site.
        if ($siteId === []) {
            return [];
        }

        // Disabled campaigns keep their history here; this is reporting, not a
        // selector for sending.
        $campaignQuery = Campaign::find()->status(null);
        if ($siteId !== 'all') {
            $campaignQuery->siteId($siteId);
        }
        // Filter by specific campaign if selected
        if ($campaignId !== 'all') {
            $campaignQuery->id($campaignId);
        }
        $campaigns = $campaignQuery->all();

        if ($campaigns === []) {
            return [];
        }

        // Collect (campaignId, siteId) pairs we care about, then aggregate all
        // counts in a single GROUP BY query instead of 2× cloned counts per
        // campaign (was up to 100 queries on a 50-campaign analytics page).
        $campaignIds = [];
        $siteIds = [];
        foreach ($campaigns as $campaign) {
            $campaignIds[(int)$campaign->id] = true;
            $siteIds[(int)$campaign->siteId] = true;
        }

        $bounds = DateRangeHelper::getBounds($dateRange);
        // Aggregate the same metric set as getOverviewStats() per (campaign,
        // site) so the analytics export can read this directly instead of
        // looping getOverviewStats() per row.
        $statsQuery = $this->joinSubmissions($this->scopeQuery(array_keys($campaignIds), array_keys($siteIds)));
        $this->whereAnyEventInRange($statsQuery, $bounds);
        $statsRows = $statsQuery
            ->select([
                'campaignId' => self::RECIPIENTS . '.campaignId',
                'siteId' => self::RECIPIENTS . '.siteId',
            ] + $this->metricSelect($statsQuery, $bounds))
            ->groupBy([self::RECIPIENTS . '.campaignId', self::RECIPIENTS . '.siteId'])
            ->all();

        $statsByKey = [];
        foreach ($statsRows as $row) {
            $statsByKey[$row['campaignId'] . '-' . $row['siteId']] = $row;
        }

        $result = [];
        foreach ($campaigns as $campaign) {
            $stats = $statsByKey[$campaign->id . '-' . $campaign->siteId] ?? null;
            $totalRecipients = (int)($stats['totalRecipients'] ?? 0);
            $emailsSent = (int)($stats['emailsSent'] ?? 0);
            $smsSent = (int)($stats['smsSent'] ?? 0);
            $emailsOpened = (int)($stats['emailsOpened'] ?? 0);
            $smsOpened = (int)($stats['smsOpened'] ?? 0);
            $submissions = (int)($stats['submissions'] ?? 0);
            $expired = (int)($stats['expired'] ?? 0);

            $result[] = [
                'campaignId' => $campaign->id,
                'campaignName' => $campaign->title,
                'siteId' => $campaign->siteId,
                'totalRecipients' => $totalRecipients,
                'emailsSent' => $emailsSent,
                'smsSent' => $smsSent,
                'emailsOpened' => $emailsOpened,
                'smsOpened' => $smsOpened,
                'submissions' => $submissions,
                'expired' => $expired,
                'conversionRate' => $totalRecipients > 0 ? round(($submissions / $totalRecipients) * 100, 1) : 0,
                'emailOpenRate' => $emailsSent > 0 ? min(100, round(($emailsOpened / $emailsSent) * 100, 1)) : 0,
                'smsOpenRate' => $smsSent > 0 ? min(100, round(($smsOpened / $smsSent) * 100, 1)) : 0,
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
        $options = [
            ['value' => 'all', 'label' => Craft::t('campaign-manager', 'All Campaigns')],
        ];

        // An empty site list means no site. Craft would read [] as the current site.
        if ($siteId === []) {
            return $options;
        }

        $campaignQuery = Campaign::find()->status(null)->unique();
        if ($siteId !== 'all') {
            $campaignQuery->siteId($siteId);
        }
        $campaigns = $campaignQuery->orderBy(['title' => SORT_ASC])->all();

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
     * @param int|array<int>|null $siteId Site ID, array of site IDs, or null for all sites
     * @param string $dateRange Date range parameter
     * @return array<string, int|float>
     */
    public function getCampaignStats(int $campaignId, int|array|null $siteId, string $dateRange): array
    {
        return $this->getOverviewStats($campaignId, $siteId ?? 'all', $dateRange);
    }

    /**
     * Get per-campaign daily trend data (alias for template use)
     *
     * @param int $campaignId Campaign ID
     * @param int|array<int>|null $siteId Site ID, array of site IDs, or null for all sites
     * @param string $dateRange Date range parameter
     * @return array<string, mixed>
     */
    public function getCampaignDailyTrend(int $campaignId, int|array|null $siteId, string $dateRange): array
    {
        $siteId ??= 'all';
        $dates = $this->getDateRangeFromParam($dateRange, $campaignId, $siteId);
        $bounds = DateRangeHelper::getBounds($dateRange);
        $scope = $this->scopeQuery($campaignId, $siteId);

        // Sends and opens add both channels into one series; each event is
        // bucketed by the day it happened.
        $sent = $this->mergeDailyCounts(
            $this->dailyCounts($scope, self::RECIPIENTS . '.emailSendDate', $bounds),
            $this->dailyCounts($scope, self::RECIPIENTS . '.smsSendDate', $bounds),
        );
        $opened = $this->mergeDailyCounts(
            $this->dailyCounts($scope, self::RECIPIENTS . '.emailOpenDate', $bounds),
            $this->dailyCounts($scope, self::RECIPIENTS . '.smsOpenDate', $bounds),
        );
        $submissions = $this->dailyCounts($this->joinSubmissions($scope), self::SUBMISSIONS . '.dateCreated', $bounds);

        $labels = [];
        $sentData = [];
        $openedData = [];
        $submissionData = [];
        $date = clone $dates['start'];
        while ($date <= $dates['end']) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $sentData[] = $sent[$dateStr] ?? 0;
            $openedData[] = $opened[$dateStr] ?? 0;
            $submissionData[] = $submissions[$dateStr] ?? 0;
            $date->modify('+1 day');
        }

        return [
            'labels' => $labels,
            'sent' => $sentData,
            'opened' => $openedData,
            'submissions' => $submissionData,
        ];
    }

    /**
     * Recipients of the campaign and site scope, without any date filter.
     *
     * @param int|string|array<int> $campaignId Campaign ID, array of campaign IDs, or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     */
    private function scopeQuery(int|string|array $campaignId, int|string|array $siteId): Query
    {
        $query = (new Query())
            ->from([self::RECIPIENTS => RecipientRecord::tableName()]);

        if ($campaignId !== 'all') {
            $query->andWhere([self::RECIPIENTS . '.campaignId' => $campaignId]);
        }

        if ($siteId !== 'all') {
            $query->andWhere([self::RECIPIENTS . '.siteId' => $siteId]);
        }

        return $query;
    }

    /**
     * Join the Formie submission a recipient answered with, which carries the
     * response date. A left join keeps unanswered recipients in the result.
     */
    private function joinSubmissions(Query $query, bool $inner = false): Query
    {
        $query = clone $query;
        $on = '[[' . self::SUBMISSIONS . '.id]] = [[' . self::RECIPIENTS . '.submissionId]]';
        $table = [self::SUBMISSIONS => '{{%formie_submissions}}'];

        return $inner ? $query->innerJoin($table, $on) : $query->leftJoin($table, $on);
    }

    /**
     * Condition for an event column holding a moment inside the range. The
     * start is inclusive and the end exclusive; without bounds (all time) the
     * event only has to have happened.
     *
     * @param array{start: \DateTime|null, end: \DateTime|null} $bounds
     * @return array<mixed>
     */
    private function eventInRange(string $column, array $bounds): array
    {
        $condition = ['and', ['not', [$column => null]]];

        if ($bounds['start']) {
            $condition[] = ['>=', $column, Db::prepareDateForDb($bounds['start'])];
        }
        if ($bounds['end']) {
            $condition[] = ['<', $column, Db::prepareDateForDb($bounds['end'])];
        }

        return $condition;
    }

    /**
     * Condition for an unanswered invitation whose expiry fell inside the
     * range and has passed.
     *
     * @param array{start: \DateTime|null, end: \DateTime|null} $bounds
     * @return array<mixed>
     */
    private function expiredInRange(array $bounds): array
    {
        $expiry = self::RECIPIENTS . '.invitationExpiryDate';

        return [
            'and',
            $this->eventInRange($expiry, $bounds),
            ['<', $expiry, Db::prepareDateForDb(new \DateTime())],
            [self::RECIPIENTS . '.submissionId' => null],
        ];
    }

    /**
     * Keep only recipients with at least one event inside a bounded range, so
     * the aggregate never has to visit rows the range cannot count.
     *
     * @param array{start: \DateTime|null, end: \DateTime|null} $bounds
     */
    private function whereAnyEventInRange(Query $query, array $bounds): void
    {
        if (!$bounds['start'] && !$bounds['end']) {
            return;
        }

        $condition = ['or'];
        foreach ($this->eventColumns() as $column) {
            $condition[] = $this->eventInRange($column, $bounds);
        }

        $query->andWhere($condition);
    }

    /**
     * The event columns a date range can count, with the submissions join in place.
     *
     * @return list<string>
     */
    private function eventColumns(): array
    {
        return [
            self::RECIPIENTS . '.dateCreated',
            self::RECIPIENTS . '.emailSendDate',
            self::RECIPIENTS . '.smsSendDate',
            self::RECIPIENTS . '.emailOpenDate',
            self::RECIPIENTS . '.smsOpenDate',
            self::RECIPIENTS . '.invitationExpiryDate',
            self::SUBMISSIONS . '.dateCreated',
        ];
    }

    /**
     * The summary figures as aggregate selections, every one counting its own
     * event inside the range. The query must have the submissions join.
     *
     * @param array{start: \DateTime|null, end: \DateTime|null} $bounds
     * @return array<string, Expression>
     */
    private function metricSelect(Query $query, array $bounds): array
    {
        $created = $this->eventInRange(self::RECIPIENTS . '.dateCreated', $bounds);
        $emailSent = $this->eventInRange(self::RECIPIENTS . '.emailSendDate', $bounds);
        $smsSent = $this->eventInRange(self::RECIPIENTS . '.smsSendDate', $bounds);
        $emailOpened = $this->eventInRange(self::RECIPIENTS . '.emailOpenDate', $bounds);
        $smsOpened = $this->eventInRange(self::RECIPIENTS . '.smsOpenDate', $bounds);
        $responded = $this->eventInRange(self::SUBMISSIONS . '.dateCreated', $bounds);

        return [
            'totalRecipients' => $this->countWhere($query, $created),
            'emailsSent' => $this->countWhere($query, $emailSent),
            'smsSent' => $this->countWhere($query, $smsSent),
            'emailsOpened' => $this->countWhere($query, $emailOpened),
            'smsOpened' => $this->countWhere($query, $smsOpened),
            // Unique recipients reached / opened inside the range, distinct from
            // the per-channel sums so a recipient reached on both channels
            // counts once.
            'uniqueInvited' => $this->countWhere($query, ['or', $emailSent, $smsSent]),
            'uniqueOpened' => $this->countWhere($query, ['or', $emailOpened, $smsOpened]),
            'submissions' => $this->countWhere($query, $responded),
            'expired' => $this->countWhere($query, $this->expiredInRange($bounds)),
        ];
    }

    /**
     * SUM(CASE WHEN … THEN 1 ELSE 0 END) for a condition, with its parameters
     * bound on the query it will run in.
     *
     * @param array<mixed> $condition
     */
    private function countWhere(Query $query, array $condition): Expression
    {
        $params = $query->params;
        $sql = Craft::$app->getDb()->getQueryBuilder()->buildCondition($condition, $params);
        $query->addParams($params);

        return new Expression("SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END)");
    }

    /**
     * Count the events of a column per local calendar day inside the range.
     *
     * @param array{start: \DateTime|null, end: \DateTime|null} $bounds
     * @return array<string, int> Counts keyed by `Y-m-d`
     */
    private function dailyCounts(Query $query, string $column, array $bounds): array
    {
        $localDate = DateFormatHelper::localDateExpression($column);

        $rows = (clone $query)
            ->select(['date' => $localDate, 'count' => new Expression('COUNT(*)')])
            ->andWhere($this->eventInRange($column, $bounds))
            ->groupBy($localDate)
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['date']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Add two per-day count series together.
     *
     * @param array<string, int> $first
     * @param array<string, int> $second
     * @return array<string, int>
     */
    private function mergeDailyCounts(array $first, array $second): array
    {
        foreach ($second as $date => $count) {
            $first[$date] = ($first[$date] ?? 0) + $count;
        }

        return $first;
    }

    /**
     * The oldest moment any retained event in the scope happened, or null when
     * the scope has no recipient.
     */
    private function earliestEventDate(int|string $campaignId, int|string|array $siteId): ?\DateTime
    {
        $query = $this->joinSubmissions($this->scopeQuery($campaignId, $siteId));

        $select = [];
        foreach ($this->eventColumns() as $index => $column) {
            $select['event' . $index] = new Expression('MIN([[' . $column . ']])');
        }

        $row = $query->select($select)->one();
        if ($row === false) {
            return null;
        }

        $earliest = null;
        foreach ($row as $value) {
            $date = $value !== null ? DateTimeHelper::toDateTime($value) : false;
            if ($date !== false && ($earliest === null || $date < $earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * Recipients of the scope selected by the chosen date basis: "sent" keeps
     * recipients invited inside the range on either channel, "response" keeps
     * recipients whose form was submitted inside it.
     *
     * @param int|string $campaignId Campaign ID or 'all'
     * @param int|string|array<int> $siteId Site ID, array of site IDs, or 'all'
     * @param string $dateRange Date range parameter
     * @param string $dateBasis Date basis: 'sent' or 'response'
     * @return Query
     */
    private function buildRecipientQuery(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis = 'sent'): Query
    {
        $bounds = DateRangeHelper::getBounds($dateRange);
        $query = $this->scopeQuery($campaignId, $siteId);

        if ($dateBasis === 'response') {
            return $this->joinSubmissions($query, true)
                ->andWhere($this->eventInRange(self::SUBMISSIONS . '.dateCreated', $bounds));
        }

        return $query->andWhere([
            'or',
            $this->eventInRange(self::RECIPIENTS . '.emailSendDate', $bounds),
            $this->eventInRange(self::RECIPIENTS . '.smsSendDate', $bounds),
        ]);
    }

    /**
     * Normalize a siteId filter into a flat list of site IDs.
     *
     * `all` is every site of the project, whoever is calling: a template or
     * service call gets the same answer for the same arguments. Control
     * panel callers pass the sites their user may edit explicitly. An empty
     * list stays empty and means no site.
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
            return SiteAccessHelper::allSiteIds();
        }

        return [(int)$siteId];
    }

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

        // Pre-fetch submissions for every (campaign, site) pair in scope — one
        // recipient query + one submission batch — instead of `_getSubmissionsInScope`
        // per campaign (was N pairs of queries for N campaigns). Each row's
        // formId is verified during distribution so per-campaign formId filtering
        // is preserved.
        $submissionsByKey = $this->_loadSubmissionsByCampaignSite($campaigns, $dateRange, $dateBasis);

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

            $submissions = $submissionsByKey[$campaign->id . '-' . $campaign->siteId] ?? [];
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

        // Resolve field info (called once — getRatingStats() used to call this
        // again internally before the round-8 refactor; we now skip getRatingStats
        // entirely and use the loaded submission count as the zero-response guard).
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

        $dates = $this->getDateRangeFromParam($dateRange, $campaignId, $siteId);
        $start = $dates['start'];
        $end = $dates['end'];

        // Determine bucket size
        $daysDiff = (int)$start->diff($end)->days;
        $byWeek = $daysDiff > 90;

        // Fetch all submissions in scope (single load — also serves as the
        // zero-response early-out, replacing the prior getRatingStats() call).
        $allSubmissions = $this->_getSubmissionsInScope($campaignId, $siteId, $dateRange, $dateBasis, $targetFieldInfo['formId']);
        if ($allSubmissions === []) {
            return $empty;
        }

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
     *                    One bucket per value the field offers, from minValue to maxValue,
     *                    half-star values included when the field allows them; a value
     *                    nobody chose is 0.
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

        // Star/Emoji: the rating field returns one ordered bucket row per
        // value it offers (half stars included when allowed), each carrying
        // the number of responses that chose it, with zero for unchosen values.
        $labels = [];
        $data = [];
        foreach ($stats['distribution'] ?? [] as $bucket) {
            if (!is_array($bucket) || !isset($bucket['value'])) {
                continue;
            }
            $labels[] = (string)(float)$bucket['value'];
            $data[] = (int)($bucket['count'] ?? 0);
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
     * Batch-load submissions for a set of (campaign, site) pairs into a map
     * keyed by `"{campaignId}-{siteId}"`. Replaces N pairs of queries from
     * `_getSubmissionsInScope()` called in a loop with one recipient query
     * plus one submission fetch.
     *
     * @param array<Campaign> $campaigns
     * @return array<string, Submission[]>
     */
    private function _loadSubmissionsByCampaignSite(array $campaigns, string $dateRange, string $dateBasis): array
    {
        if ($campaigns === []) {
            return [];
        }

        $campaignIdsInScope = [];
        $siteIdsInScope = [];
        $formIdByKey = [];
        foreach ($campaigns as $campaign) {
            $campaignIdsInScope[(int)$campaign->id] = true;
            $siteIdsInScope[(int)$campaign->siteId] = true;
            $formIdByKey[$campaign->id . '-' . $campaign->siteId] = (int)($campaign->formId ?? 0);
        }

        $recipientTable = self::RECIPIENTS;
        $recipientRows = $this->buildRecipientQuery('all', 'all', $dateRange, $dateBasis)
            ->andWhere([$recipientTable . '.campaignId' => array_keys($campaignIdsInScope)])
            ->andWhere([$recipientTable . '.siteId' => array_keys($siteIdsInScope)])
            ->andWhere(['not', [$recipientTable . '.submissionId' => null]])
            ->select([
                'campaignId' => $recipientTable . '.campaignId',
                'siteId' => $recipientTable . '.siteId',
                'submissionId' => $recipientTable . '.submissionId',
            ])
            ->all();

        if ($recipientRows === []) {
            return [];
        }

        $submissionIdsByKey = [];
        $allSubmissionIds = [];
        foreach ($recipientRows as $row) {
            $key = $row['campaignId'] . '-' . $row['siteId'];
            $submissionIdsByKey[$key][] = (int)$row['submissionId'];
            $allSubmissionIds[(int)$row['submissionId']] = true;
        }

        /** @var Submission[] $submissions */
        $submissions = Submission::find()
            ->id(array_keys($allSubmissionIds))
            ->isIncomplete(false)
            ->isSpam(false)
            ->all();

        $submissionsById = [];
        foreach ($submissions as $submission) {
            $submissionsById[$submission->id] = $submission;
        }

        $submissionsByKey = [];
        foreach ($submissionIdsByKey as $key => $subIds) {
            $expectedFormId = $formIdByKey[$key] ?? 0;
            $bucket = [];
            foreach ($subIds as $sid) {
                $sub = $submissionsById[$sid] ?? null;
                if ($sub !== null && (int)$sub->formId === $expectedFormId) {
                    $bucket[] = $sub;
                }
            }
            $submissionsByKey[$key] = $bucket;
        }

        return $submissionsByKey;
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
            ? 'Response date'
            : 'Send activity';

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
                'NPS',
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
                'Star Rating',
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

        $recipientTable = self::RECIPIENTS;

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
}
