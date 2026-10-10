<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Support\SeedsAnalyticsEventMatrix;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A date-ranged analytics figure counts the events that happened inside the
 * range, each by its own date: recipients by when they were added,
 * invitations by when they were sent, opens by when they were opened and
 * responses by when the form was submitted. "All time" covers every retained
 * event.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
final class AnalyticsEventDateRangeTest extends SiteRestrictedUserTestCase
{
    use SeedsAnalyticsEventMatrix;

    public function testSummaryCountsEachEventInTheRangeItHappened(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);

        $stats = $this->analytics()->getOverviewStats((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertSame(self::MATRIX_TOTALS, array_intersect_key($stats, self::MATRIX_TOTALS));
        self::assertEqualsWithDelta(66.7, $stats['openRate'], 0.01, 'Open rate is opens in the range over invitations sent in the range.');
        self::assertEqualsWithDelta(66.7, $stats['conversionRate'], 0.01, 'Conversion rate is responses in the range over recipients added in the range.');
        self::assertEqualsWithDelta(50.0, $stats['emailOpenRate'], 0.01);
        self::assertEqualsWithDelta(100.0, $stats['smsOpenRate'], 0.01);
    }

    public function testSummaryForAllTimeCountsEveryRetainedEvent(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);

        $stats = $this->analytics()->getOverviewStats((int)$matrix['campaign']->id, [$siteA], 'all');

        self::assertSame(self::MATRIX_ALL_TIME_TOTALS, array_intersect_key($stats, self::MATRIX_ALL_TIME_TOTALS));
    }

    public function testRecipientsAddedFollowTheCreationDate(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $campaignId = (int)$matrix['campaign']->id;

        $daily = $this->analytics()->getDailyTrend($campaignId, [$siteA], self::MATRIX_RANGE);

        self::assertSame(3, array_sum($daily['recipients']), 'Only the three recipients created inside the range were added in it.');
        self::assertSame(3, $daily['recipients'][self::MATRIX_INSIDE_DAY_INDEX]);
        self::assertSame(
            3,
            $this->analytics()->getOverviewStats($campaignId, [$siteA], self::MATRIX_RANGE)['totalRecipients'],
            'A recipient created one second before the range is not added in it, even when invited inside it.',
        );
    }

    public function testRangeEdgesIncludeTheStartAndExcludeTheEnd(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $lastIndex = $this->matrixDayCount() - 1;

        $daily = $this->analytics()->getDailyTrend((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertSame(1, $daily['emailsSent'][0], 'An email sent at the first moment of the range belongs to its first day.');
        self::assertSame(1, $daily['emailsSent'][$lastIndex], 'An email sent at the last second of the range belongs to its last day.');
        self::assertSame(4, array_sum($daily['emailsSent']), 'An email sent exactly at the exclusive end is outside the range.');
    }

    public function testDailyActivityBucketsEachSeriesByItsOwnDate(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $inside = self::MATRIX_INSIDE_DAY_INDEX;

        $daily = $this->analytics()->getDailyTrend((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertCount($this->matrixDayCount(), $daily['labels']);
        self::assertSame(['labels', 'recipients', 'emailsSent', 'smsSent', 'submissions'], array_keys($daily));
        self::assertSame(4, array_sum($daily['emailsSent']));
        self::assertSame(2, $daily['emailsSent'][$inside], 'Two old recipients were emailed on the inside day.');
        self::assertSame(2, array_sum($daily['smsSent']));
        self::assertSame(2, $daily['smsSent'][$inside]);
        self::assertSame(2, array_sum($daily['submissions']));
        self::assertSame(2, $daily['submissions'][$inside], 'Responses are bucketed by the day the form was submitted.');
    }

    public function testOpensOverTimeBucketOpensByTheirOpenDate(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $inside = self::MATRIX_INSIDE_DAY_INDEX;

        $engagement = $this->analytics()->getEngagementOverTime((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertCount($this->matrixDayCount(), $engagement['labels']);
        self::assertSame(2, array_sum($engagement['emailOpens']));
        self::assertSame(2, $engagement['emailOpens'][$inside]);
        self::assertSame(2, array_sum($engagement['smsOpens']));
        self::assertSame(2, $engagement['smsOpens'][$inside]);
    }

    public function testChannelDistributionDescribesInvitationsSentInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);

        $channels = $this->analytics()->getChannelDistribution((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertSame([3, 1, 1], $channels['values'], 'Email only, SMS only and both, counted among recipients invited inside the range.');
    }

    public function testConversionFunnelCountsUniqueRecipientsPerStageInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);

        $funnel = $this->analytics()->getConversionFunnel((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertSame([3, 5, 3, 2], $funnel['values']);
    }

    public function testUniqueCountsDoNotDoubleCountARecipientReachedOnBothChannels(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);

        $stats = $this->analytics()->getOverviewStats((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertSame(6, $stats['totalSent'], 'Channel totals add up email and SMS sends.');
        self::assertSame(5, $stats['uniqueInvited'], 'The recipient reached on both channels counts once.');
        self::assertSame(4, $stats['totalOpened']);
        self::assertSame(3, $stats['uniqueOpened']);
    }

    public function testCampaignBreakdownUsesTheSameEventDates(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $campaignId = (int)$matrix['campaign']->id;

        $rows = $this->analytics()->getCampaignBreakdown($campaignId, [$siteA], self::MATRIX_RANGE);

        self::assertCount(1, $rows);
        $expected = array_intersect_key(self::MATRIX_TOTALS, array_flip(['totalRecipients', 'emailsSent', 'smsSent', 'emailsOpened', 'smsOpened', 'submissions', 'expired']));
        self::assertSame($expected, array_intersect_key($rows[0], $expected));
        self::assertSame($campaignId, $rows[0]['campaignId']);
        self::assertSame($siteA, $rows[0]['siteId']);
    }

    public function testCampaignDailyTrendUsesTheSameEventDates(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $inside = self::MATRIX_INSIDE_DAY_INDEX;
        $lastIndex = $this->matrixDayCount() - 1;

        $trend = $this->analytics()->getCampaignDailyTrend((int)$matrix['campaign']->id, [$siteA], self::MATRIX_RANGE);

        self::assertCount($this->matrixDayCount(), $trend['labels']);
        self::assertSame(6, array_sum($trend['sent']));
        self::assertSame(1, $trend['sent'][0]);
        self::assertSame(4, $trend['sent'][$inside]);
        self::assertSame(1, $trend['sent'][$lastIndex]);
        self::assertSame(4, array_sum($trend['opened']));
        self::assertSame(4, $trend['opened'][$inside]);
        self::assertSame(2, array_sum($trend['submissions']));
        self::assertSame(2, $trend['submissions'][$inside]);
    }

    public function testExpiredCountsInvitationsThatExpiredInTheRangeUnanswered(): void
    {
        [$siteA] = $this->siteIds(1);
        $matrix = $this->seedAnalyticsEventMatrix($siteA);
        $campaignId = (int)$matrix['campaign']->id;

        self::assertSame(1, $this->analytics()->getOverviewStats($campaignId, [$siteA], self::MATRIX_RANGE)['expired']);
        self::assertSame(2, $this->analytics()->getOverviewStats($campaignId, [$siteA], 'all')['expired']);
    }

    public function testAllTimeChartsCoverEveryRetainedDay(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $utc = new DateTimeZone('UTC');
        $createdAt = (new DateTime('now', $utc))->modify('-400 days');
        $openedAt = (clone $createdAt)->modify('+1 day');
        $respondedAt = (clone $createdAt)->modify('+2 days');
        $recipient = $this->seedOwnedRecipient($campaign, $siteA, [
            'dateCreated' => Db::prepareDateForDb($createdAt),
            'emailSendDate' => Db::prepareDateForDb($createdAt),
            'emailOpenDate' => Db::prepareDateForDb($openedAt),
        ]);
        $form = \verbb\formie\elements\Form::find()->id($campaign->formId)->one();
        self::assertInstanceOf(\verbb\formie\elements\Form::class, $form);
        $this->ageSubmission($this->submitForm($form, (string)$recipient->emailInvitationCode, $siteA), $respondedAt);

        $analytics = $this->analytics();
        $campaignId = (int)$campaign->id;
        $firstLabel = (clone $createdAt)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))->format('M j');

        $daily = $analytics->getDailyTrend($campaignId, [$siteA], 'all');
        self::assertGreaterThanOrEqual(400, count($daily['labels']), 'All time reaches back to the oldest retained event.');
        self::assertSame($firstLabel, $daily['labels'][0]);
        self::assertSame(1, array_sum($daily['recipients']));
        self::assertSame(1, array_sum($daily['emailsSent']));
        self::assertSame(1, array_sum($daily['submissions']));

        $engagement = $analytics->getEngagementOverTime($campaignId, [$siteA], 'all');
        self::assertCount(count($daily['labels']), $engagement['labels']);
        self::assertSame(1, array_sum($engagement['emailOpens']));

        $trend = $analytics->getCampaignDailyTrend($campaignId, [$siteA], 'all');
        self::assertCount(count($daily['labels']), $trend['labels']);
        self::assertSame(1, array_sum($trend['sent']));
        self::assertSame(1, array_sum($trend['opened']));
        self::assertSame(1, array_sum($trend['submissions']));
    }

    public function testAllTimeChartsWithoutDataShowOnlyToday(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();

        $daily = $this->analytics()->getDailyTrend((int)$campaign->id, [$siteA], 'all');

        self::assertCount(1, $daily['labels']);
        self::assertSame(0, array_sum($daily['recipients']));
    }

    public function testRangeEdgesAreLocalMidnightsInTheConfiguredTimezone(): void
    {
        [$siteA] = $this->siteIds(1);
        $originalTimeZone = Craft::$app->getTimeZone();
        // Far enough from UTC that a late-evening local time is already the next UTC day.
        Craft::$app->setTimeZone('America/Los_Angeles');

        try {
            $campaign = $this->seedOwnedCampaign();
            $bounds = DateRangeHelper::getBounds(self::MATRIX_RANGE);
            self::assertNotNull($bounds['start']);
            self::assertNotNull($bounds['end']);
            $local = new DateTimeZone('America/Los_Angeles');
            $lastIndex = (int)(clone $bounds['start'])->setTimezone($local)->format('t') - 1;
            // 23:30 on the last local day of the range is already the first day of this month in UTC.
            $lateLastDay = (clone $bounds['end'])->setTimezone($local)->modify('-30 minutes');
            self::assertSame($lastIndex + 1, (int)$lateLastDay->format('j'));

            $this->seedOwnedRecipient($campaign, $siteA, [
                'dateCreated' => Db::prepareDateForDb((clone $bounds['start'])->modify('-30 days')),
                'emailSendDate' => Db::prepareDateForDb($bounds['start']),
            ]);
            $this->seedOwnedRecipient($campaign, $siteA, [
                'dateCreated' => Db::prepareDateForDb((clone $bounds['start'])->modify('-30 days')),
                'emailSendDate' => Db::prepareDateForDb((clone $bounds['start'])->modify('-1 second')),
            ]);
            $this->seedOwnedRecipient($campaign, $siteA, [
                'dateCreated' => Db::prepareDateForDb((clone $bounds['start'])->modify('-30 days')),
                'emailSendDate' => Db::prepareDateForDb($lateLastDay),
                'emailOpenDate' => Db::prepareDateForDb($lateLastDay),
            ]);

            $stats = $this->analytics()->getOverviewStats((int)$campaign->id, [$siteA], self::MATRIX_RANGE);
            self::assertSame(2, $stats['emailsSent'], 'Local midnight starts the range; one second earlier is outside it.');
            self::assertSame(1, $stats['emailsOpened']);

            $daily = $this->analytics()->getDailyTrend((int)$campaign->id, [$siteA], self::MATRIX_RANGE);
            self::assertCount($lastIndex + 1, $daily['labels']);
            self::assertSame(1, $daily['emailsSent'][0]);
            self::assertSame(1, $daily['emailsSent'][$lastIndex], 'A late-evening send belongs to its local day, not the next UTC day.');

            $engagement = $this->analytics()->getEngagementOverTime((int)$campaign->id, [$siteA], self::MATRIX_RANGE);
            self::assertSame(1, $engagement['emailOpens'][$lastIndex]);
        } finally {
            Craft::$app->setTimeZone($originalTimeZone);
        }

        self::assertSame($originalTimeZone, Craft::$app->getTimeZone());
    }

    public function testAnEmptyScopeStaysSafe(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $campaignId = (int)$campaign->id;
        $analytics = $this->analytics();

        $stats = $analytics->getOverviewStats($campaignId, [$siteA], self::MATRIX_RANGE);
        self::assertSame(0, $stats['totalRecipients']);
        self::assertSame(0, $stats['submissions']);
        self::assertSame(0, $stats['openRate']);
        self::assertSame([0, 0, 0], $analytics->getChannelDistribution($campaignId, [$siteA], self::MATRIX_RANGE)['values']);
        self::assertSame([0, 0, 0, 0], $analytics->getConversionFunnel($campaignId, [$siteA], self::MATRIX_RANGE)['values']);
        self::assertSame(0, array_sum($analytics->getDailyTrend($campaignId, [$siteA], self::MATRIX_RANGE)['submissions']));
        self::assertSame(0, array_sum($analytics->getEngagementOverTime($campaignId, [$siteA], self::MATRIX_RANGE)['emailOpens']));
        self::assertSame(0, array_sum($analytics->getCampaignDailyTrend($campaignId, [$siteA], self::MATRIX_RANGE)['sent']));
        self::assertSame(0, $analytics->getCampaignBreakdown($campaignId, [$siteA], self::MATRIX_RANGE)[0]['totalRecipients'] ?? 0);
    }

    private function analytics(): AnalyticsService
    {
        return CampaignManager::$plugin->analytics;
    }
}
