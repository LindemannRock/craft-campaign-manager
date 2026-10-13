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
use craft\web\View;
use DateTime;
use DateTimeZone;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\controllers\AnalyticsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Twig\Cache\CacheInterface;
use verbb\formie\elements\Form;

/**
 * The conversion funnel follows the recipients added inside the selected
 * date range through its stages as of the end of the range: how many were
 * added, how many of those had been sent an invitation, how many of those
 * had opened one, and how many of those had responded. Each stage is a
 * subset of the one before it, each recipient counts once, and the funnel
 * table never shows more than 100% or a negative drop-off.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
#[CoversClass(AnalyticsController::class)]
final class AnalyticsConversionFunnelCohortTest extends SiteRestrictedUserTestCase
{
    private const RANGE = 'lastMonth';

    private const PERMISSIONS = ['campaignManager:viewAnalytics'];

    private CacheInterface|string|false|null $originalTwigCache = null;

    protected function cleanupExternalState(): void
    {
        try {
            if ($this->originalTwigCache !== null) {
                Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_CP)->setCache($this->originalTwigCache);
                $this->originalTwigCache = null;
            }
        } finally {
            parent::cleanupExternalState();
        }
    }

    public function testStagesAreNestedSubsetsOfTheRecipientsAddedInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);

        $funnel = $this->analytics()->getConversionFunnel((int)$cohort['campaign']->id, [$siteA], self::RANGE);

        self::assertSame(['Recipients Added', 'Invitations Sent', 'Opened', 'Responded'], $funnel['labels']);
        self::assertSame([8, 6, 3, 1], $funnel['values']);
        $this->assertNested($funnel['values']);
    }

    public function testAllTimeFollowsEveryRecipientThroughEveryRetainedEvent(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);

        $funnel = $this->analytics()->getConversionFunnel((int)$cohort['campaign']->id, [$siteA], 'all');

        self::assertSame([11, 10, 4, 3], $funnel['values'], 'Without a range end, invitations sent, opened and answered at any time count.');
        $this->assertNested($funnel['values']);
    }

    public function testActivityOfRecipientsAddedBeforeTheRangeStaysOnTheActivityMetrics(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);
        $campaignId = (int)$cohort['campaign']->id;

        $stats = $this->analytics()->getOverviewStats($campaignId, [$siteA], self::RANGE);

        self::assertSame(8, $stats['totalRecipients']);
        self::assertSame(8, $stats['uniqueInvited'], 'The old recipient invited inside the range still counts as an invitation sent in it.');
        self::assertSame(4, $stats['uniqueOpened']);
        self::assertSame(3, $stats['submissions'], 'The old recipient and the unopened response still count as responses in the range.');
        self::assertSame(3, $this->analytics()->getDailyTrend($campaignId, [$siteA], self::RANGE)['submissions'][10] ?? null, 'Responses are still charted by the day they were submitted.');
    }

    public function testAnInvitationOpenedAtOrAfterTheRangeEndCountsAsSentButNotOpened(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $form = Form::find()->id($campaign->formId)->one();
        if (!$form instanceof Form) {
            throw new RuntimeException('The campaign has no form.');
        }
        // Sent inside the range, opened exactly at its exclusive end.
        $this->seedRecipientWithEvents($campaign, $siteA, $form, ['created' => 'inside', 'emailSent' => 'lastSecond', 'emailOpened' => 'end']);
        // Sent inside the range, opened and answered after it ended.
        $this->seedRecipientWithEvents($campaign, $siteA, $form, ['created' => 'inside', 'smsSent' => 'inside', 'smsOpened' => 'after', 'responded' => 'after']);
        // Sent and opened at the last second of the range.
        $this->seedRecipientWithEvents($campaign, $siteA, $form, ['created' => 'inside', 'emailSent' => 'inside', 'emailOpened' => 'lastSecond']);

        $funnel = $this->analytics()->getConversionFunnel((int)$campaign->id, [$siteA], self::RANGE);

        self::assertSame([3, 3, 1, 0], $funnel['values'], 'An open at or after the exclusive end is not an open as of the end of the range; one second before it is.');
        $this->assertNested($funnel['values']);
        self::assertSame([3, 3, 3, 1], $this->analytics()->getConversionFunnel((int)$campaign->id, [$siteA], 'all')['values'], 'Without a range end, the later opens and the later response count.');
    }

    public function testTheFunnelFollowsTheSiteScope(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        // Every campaign on these sites may include recipients this test does not own; prove the delta.
        $allCampaignsBefore = $this->analytics()->getConversionFunnel('all', [$siteA, $siteB], self::RANGE)['values'];
        $cohort = $this->seedCohort($siteA);
        $campaign = $cohort['campaign'];
        $this->seedRecipientWithEvents($campaign, $siteB, $cohort['form'], ['created' => 'inside', 'emailSent' => 'inside', 'emailOpened' => 'inside', 'responded' => 'inside']);
        $campaignId = (int)$campaign->id;

        self::assertSame([8, 6, 3, 1], $this->analytics()->getConversionFunnel($campaignId, [$siteA], self::RANGE)['values']);
        self::assertSame([1, 1, 1, 1], $this->analytics()->getConversionFunnel($campaignId, $siteB, self::RANGE)['values']);
        self::assertSame([9, 7, 4, 2], $this->analytics()->getConversionFunnel($campaignId, [$siteA, $siteB], self::RANGE)['values']);
        self::assertSame([9, 7, 4, 2], $this->analytics()->getConversionFunnel($campaignId, 'all', self::RANGE)['values']);
        self::assertSame([0, 0, 0, 0], $this->analytics()->getConversionFunnel($campaignId, [], self::RANGE)['values'], 'An empty site list is no site.');
        self::assertSame(
            array_map(static fn(int $before, int $added): int => $before + $added, $allCampaignsBefore, [9, 7, 4, 2]),
            $this->analytics()->getConversionFunnel('all', [$siteA, $siteB], self::RANGE)['values'],
            'Every campaign across the sites gains exactly this cohort.'
        );
    }

    public function testADisabledCampaignKeepsItsFunnel(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);
        $before = $this->analytics()->getConversionFunnel((int)$cohort['campaign']->id, [$siteA], self::RANGE);

        $this->disableOwnedCampaignForSite($cohort['campaign'], $siteA);

        self::assertSame($before, $this->analytics()->getConversionFunnel((int)$cohort['campaign']->id, [$siteA], self::RANGE), 'Reporting keeps the history of a disabled campaign.');
    }

    public function testTheFunnelTableNeverExceedsOneHundredPercentOrDropsBelowZero(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);
        $campaignId = (int)$cohort['campaign']->id;
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['campaign' => (string)$campaignId, 'dateRange' => self::RANGE, 'siteId' => 'all']);
        $variables = (new CapturingAnalyticsController('analytics', CampaignManager::$plugin))->actionIndex()->data['variables'];
        self::assertSame([8, 6, 3, 1], $variables['conversionFunnel']['values'], 'The page carries the funnel the chart shows.');

        $html = $this->renderConversionTab($variables);

        $rows = $this->funnelRows($html);
        self::assertSame(['Recipients Added', 'Invitations Sent', 'Opened', 'Responded'], array_column($rows, 'stage'));
        self::assertSame([8, 6, 3, 1], array_column($rows, 'count'));
        self::assertSame([100.0, 75.0, 37.5, 12.5], array_column($rows, 'percentage'));
        self::assertSame([null, 25.0, 50.0, 66.7], array_column($rows, 'dropoff'));
        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(0, $row['percentage']);
            self::assertLessThanOrEqual(100, $row['percentage']);
            if ($row['dropoff'] !== null) {
                self::assertGreaterThanOrEqual(0, $row['dropoff']);
                self::assertLessThanOrEqual(100, $row['dropoff']);
            }
        }
        self::assertStringContainsString('Recipients added in the selected date range', $html, 'The table says which recipients it follows.');
    }

    public function testTheChartEndpointReturnsTheSameFunnelAsThePage(): void
    {
        [$siteA] = $this->siteIds(1);
        $cohort = $this->seedCohort($siteA);
        $campaignId = (int)$cohort['campaign']->id;
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['type' => 'funnel', 'campaignId' => (string)$campaignId, 'dateRange' => self::RANGE, 'siteId' => 'all']);
        $payload = (new CapturingAnalyticsController('analytics', CampaignManager::$plugin))->actionGetData()->data;

        self::assertTrue($payload['success']);
        self::assertSame($this->analytics()->getConversionFunnel($campaignId, [$siteA], self::RANGE), $payload['data']);
    }

    /**
     * @param list<int> $values
     */
    private function assertNested(array $values): void
    {
        [$added, $sent, $opened, $responded] = $values;
        self::assertGreaterThanOrEqual($sent, $added);
        self::assertGreaterThanOrEqual($opened, $sent);
        self::assertGreaterThanOrEqual($responded, $opened);
    }

    /**
     * The moments the cohort is built around, all in UTC.
     *
     * @return array<string, DateTime>
     */
    private function instants(): array
    {
        $bounds = DateRangeHelper::getBounds(self::RANGE);
        if ($bounds['start'] === null || $bounds['end'] === null) {
            throw new RuntimeException('The range must have a start and an end.');
        }
        $start = $bounds['start'];
        $end = $bounds['end'];
        $after = (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 minute');
        if ($after < $end) {
            $after = clone $end;
        }

        return [
            'before' => (clone $start)->modify('-20 days'),
            'justBefore' => (clone $start)->modify('-1 second'),
            'start' => $start,
            'inside' => (clone $start)->modify('+10 days +12 hours'),
            'lastSecond' => (clone $end)->modify('-1 second'),
            'end' => $end,
            'after' => $after,
        ];
    }

    /**
     * One campaign whose recipients were added, invited, opened and answered
     * at known moments around the range.
     *
     * @return array{campaign: Campaign, form: Form, recipients: array<string, RecipientRecord>}
     */
    private function seedCohort(int $siteId): array
    {
        $campaign = $this->seedOwnedCampaign();
        $form = Form::find()->id($campaign->formId)->one();
        if (!$form instanceof Form) {
            throw new RuntimeException('The cohort campaign has no form.');
        }

        $spec = [
            // Added before the range; invited, opened and answered inside it: not in the cohort.
            'oldActiveNow' => ['created' => 'before', 'emailSent' => 'inside', 'emailOpened' => 'inside', 'responded' => 'inside'],
            // Added inside; nothing else happened.
            'newQuiet' => ['created' => 'inside'],
            // Added inside and emailed inside.
            'newSentOnly' => ['created' => 'inside', 'emailSent' => 'inside'],
            // Added inside, only invited after the range ended.
            'newSentLater' => ['created' => 'inside', 'emailSent' => 'after'],
            // Added inside, reached and opened on both channels: counts once per stage.
            'newDualOpened' => ['created' => 'inside', 'emailSent' => 'inside', 'smsSent' => 'inside', 'emailOpened' => 'inside', 'smsOpened' => 'inside'],
            // Added inside, invited and answered inside, but never opened: the response is not in the funnel.
            'newRespondedUnopened' => ['created' => 'inside', 'emailSent' => 'inside', 'responded' => 'inside'],
            // Added inside and converted inside.
            'newConverted' => ['created' => 'inside', 'emailSent' => 'inside', 'emailOpened' => 'inside', 'responded' => 'inside'],
            // Added inside, opened inside, answered only after the range ended.
            'newConvertedLater' => ['created' => 'inside', 'smsSent' => 'inside', 'smsOpened' => 'inside', 'responded' => 'after'],
            // Added at the first moment of the range, invited at its last second.
            'addedAtStart' => ['created' => 'start', 'emailSent' => 'lastSecond'],
            // Added exactly at the exclusive end of the range.
            'addedAtEnd' => ['created' => 'end', 'emailSent' => 'after'],
            // Added one second before the range began.
            'addedJustBefore' => ['created' => 'justBefore', 'emailSent' => 'inside'],
        ];

        $recipients = [];
        foreach ($spec as $key => $events) {
            $recipients[$key] = $this->seedRecipientWithEvents($campaign, $siteId, $form, $events);
        }

        return ['campaign' => $campaign, 'form' => $form, 'recipients' => $recipients];
    }

    /**
     * @param array<string, string> $events Event name => instant name
     */
    private function seedRecipientWithEvents(Campaign $campaign, int $siteId, Form $form, array $events): RecipientRecord
    {
        $t = $this->instants();
        $attributes = [
            'dateCreated' => Db::prepareDateForDb($t[$events['created']]),
            'sms' => '+9715000' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT),
        ];
        foreach (['emailSent' => 'emailSendDate', 'smsSent' => 'smsSendDate', 'emailOpened' => 'emailOpenDate', 'smsOpened' => 'smsOpenDate'] as $event => $column) {
            if (isset($events[$event])) {
                $attributes[$column] = Db::prepareDateForDb($t[$events[$event]]);
            }
        }

        $recipient = $this->seedOwnedRecipient($campaign, $siteId, $attributes);

        if (isset($events['responded'])) {
            $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId);
            if ($this->reloadRecipient($recipient)->submissionId !== (int)$submission->id) {
                throw new RuntimeException('The response was not linked to its recipient.');
            }
            $this->ageSubmission($submission, $t[$events['responded']]);
            $recipient = $this->reloadRecipient($recipient);
        }

        return $recipient;
    }

    /**
     * Render the Conversion tab with the page's variables, compiling in
     * memory so no compiled template is left behind.
     *
     * @param array<string, mixed> $variables
     */
    private function renderConversionTab(array $variables): string
    {
        $twig = Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_CP);
        $this->originalTwigCache = $twig->getCache();
        $twig->setCache(false);

        return Craft::$app->getView()->renderTemplate('campaign-manager/analytics/_partials/conversion', $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * The rows of the rendered funnel table.
     *
     * @return list<array{stage: string, count: int, percentage: float, dropoff: float|null}>
     */
    private function funnelRows(string $html): array
    {
        if (preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $table) !== 1) {
            self::fail('The funnel table was not rendered.');
        }
        preg_match_all('/<tr>(.*?)<\/tr>/s', $table[1], $rows);

        $parsed = [];
        foreach ($rows[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);
            $text = array_map(static fn(string $cell): string => trim(html_entity_decode(strip_tags($cell))), $cells[1]);
            $parsed[] = [
                'stage' => $text[0],
                'count' => (int)str_replace(',', '', $text[1]),
                'percentage' => (float)rtrim($text[2], '%'),
                'dropoff' => $text[3] === '-' ? null : (float)rtrim($text[3], '%'),
            ];
        }

        return $parsed;
    }

    private function analytics(): AnalyticsService
    {
        return CampaignManager::$plugin->analytics;
    }
}
