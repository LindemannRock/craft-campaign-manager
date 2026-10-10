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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Stubs\CapturingAnalyticsController;
use lindemannrock\campaignmanager\tests\Support\SeedsAnalyticsEventMatrix;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\formieratingfield\fields\Rating;
use PHPUnit\Framework\Attributes\CoversClass;
use verbb\formie\elements\Form;

/**
 * The ratings tab's distribution chart shows, for every value a rating field
 * offers, how many responses chose it. Star and emoji fields list every
 * bucket from the lowest to the highest value, half stars included when the
 * field allows them, and a bucket nobody chose shows zero. NPS fields keep
 * their promoter, passive and detractor segments. The buckets follow the
 * selected campaign, sites, date range and date basis, and the chart
 * endpoint returns exactly what the service computes.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
final class AnalyticsRatingDistributionTest extends SiteRestrictedUserTestCase
{
    use SeedsAnalyticsEventMatrix;

    private const RATING_HANDLE = 'satisfaction';

    protected function setUp(): void
    {
        parent::setUp();

        if (!PluginHelper::isPluginEnabled('formie-rating-field') || !class_exists(Rating::class)) {
            self::markTestSkipped('Rating analytics need the Formie rating field plugin.');
        }
    }

    public function testStarBucketsCarryTheResponseCountOfEachValue(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_STAR, 1, 5);
        $campaign = $this->seedRatedCampaign($form, $siteA, [5, 5, 5, 4, 2]);

        $distribution = $this->distribution((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        self::assertSame(['1', '2', '3', '4', '5'], $distribution['labels']);
        self::assertSame([0, 1, 0, 1, 3], $distribution['data'], 'Each bucket counts the responses that chose that value; a value nobody chose is zero.');
        self::assertSame('bar', $distribution['chartType']);
    }

    public function testHalfStarBucketsAreKept(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_STAR, 1, 5, ['allowHalfRatings' => true]);
        $campaign = $this->seedRatedCampaign($form, $siteA, [1.5, 1.5, 3, 5]);

        $distribution = $this->distribution((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        self::assertSame(['1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'], $distribution['labels']);
        self::assertSame([0, 2, 0, 0, 1, 0, 0, 0, 1], $distribution['data']);
    }

    public function testEmojiBucketsCarryTheResponseCountOfEachValue(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_EMOJI, 1, 5);
        $campaign = $this->seedRatedCampaign($form, $siteA, [1, 1, 2]);

        $distribution = $this->distribution((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        self::assertSame(['1', '2', '3', '4', '5'], $distribution['labels']);
        self::assertSame([2, 1, 0, 0, 0], $distribution['data']);
        self::assertSame('bar', $distribution['chartType']);
    }

    public function testNpsSegmentsCountPromotersPassivesAndDetractors(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_NPS, 0, 10);
        $campaign = $this->seedRatedCampaign($form, $siteA, [10, 9, 7, 3]);

        $distribution = $this->distribution((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        self::assertSame([
            Craft::t('campaign-manager', 'Promoters'),
            Craft::t('campaign-manager', 'Passives'),
            Craft::t('campaign-manager', 'Detractors'),
        ], $distribution['labels']);
        self::assertSame([2, 1, 1], $distribution['data']);
        self::assertSame('doughnut', $distribution['chartType']);
    }

    public function testTheChartEndpointReturnsTheServiceBuckets(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_STAR, 1, 5);
        $campaign = $this->seedRatedCampaign($form, $siteA, [5, 5, 5, 4, 2]);
        $this->actAsUserRestrictedTo([$siteA], ['campaignManager:viewAnalytics']);

        $this->request('POST', [], [
            'type' => 'rating-distribution',
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteA,
            'dateRange' => 'all',
            'dateBasis' => 'sent',
            'fieldId' => (string)$fieldId,
        ]);
        $payload = (new CapturingAnalyticsController('analytics', CampaignManager::$plugin))->actionGetData()->data;

        self::assertTrue($payload['success']);
        self::assertSame(['1', '2', '3', '4', '5'], $payload['data']['labels']);
        self::assertSame([0, 1, 0, 1, 3], $payload['data']['data']);
        self::assertSame('bar', $payload['data']['chartType']);
        self::assertSame($this->distribution((int)$campaign->id, $siteA, 'all', 'sent', $fieldId), $payload['data']);
    }

    public function testBucketsFollowTheSelectedCampaignAndSites(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_STAR, 1, 5);
        $campaignA = $this->seedRatedCampaign($form, $siteA, [5]);
        $campaignB = $this->seedRatedCampaign($form, $siteB, [1, 1]);

        self::assertSame([0, 0, 0, 0, 1], $this->distribution((int)$campaignA->id, $siteA, 'all', 'sent', $fieldId)['data'], 'One campaign on one site.');
        self::assertSame([2, 0, 0, 0, 0], $this->distribution((int)$campaignB->id, [$siteB], 'all', 'sent', $fieldId)['data']);
        self::assertSame([2, 0, 0, 0, 1], $this->distribution('all', [$siteA, $siteB], 'all', 'sent', $fieldId)['data'], 'All campaigns across the editable sites.');
        self::assertSame([2, 0, 0, 0, 0], $this->distribution('all', [$siteB], 'all', 'sent', $fieldId)['data'], 'Only the editable site.');
        self::assertSame([], $this->distribution((int)$campaignA->id, $siteB, 'all', 'sent', $fieldId)['data'], 'The campaign has no response on that site.');
        self::assertSame([], $this->distribution('all', [], 'all', 'sent', $fieldId)['data'], 'No editable site, no buckets.');
    }

    public function testBucketsFollowTheDateRangeAndTheDateBasis(): void
    {
        [$siteA] = $this->siteIds(1);
        $t = $this->matrixInstants();
        [$form, $fieldId] = $this->seedRatingForm(Rating::RATING_TYPE_STAR, 1, 5);
        $campaign = $this->seedCampaign($form, ['siteId' => $siteA]);
        $this->ownCampaign((int)$campaign->id);

        // Invited and answering inside the range.
        $this->seedRatedResponse($campaign, $form, $siteA, $t['inside'], $t['inside'], 4);
        // Invited before the range, answering inside it.
        $this->seedRatedResponse($campaign, $form, $siteA, $t['before'], $t['inside'], 2);
        // Invited inside the range, answering after it.
        $this->seedRatedResponse($campaign, $form, $siteA, $t['inside'], $t['after'], 5);

        $campaignId = (int)$campaign->id;
        self::assertSame([0, 0, 0, 1, 1], $this->distribution($campaignId, [$siteA], self::MATRIX_RANGE, 'sent', $fieldId)['data'], 'Send activity keeps the recipients invited inside the range.');
        self::assertSame([0, 1, 0, 1, 0], $this->distribution($campaignId, [$siteA], self::MATRIX_RANGE, 'response', $fieldId)['data'], 'Response date keeps the responses submitted inside the range.');
        self::assertSame([0, 1, 0, 1, 1], $this->distribution($campaignId, [$siteA], 'all', 'sent', $fieldId)['data']);
    }

    /**
     * @param int|string $campaignId
     * @param int|string|array<int> $siteId
     * @return array{labels: array<string>, data: array<int>, chartType: string}
     */
    private function distribution(int|string $campaignId, int|string|array $siteId, string $dateRange, string $dateBasis, int $fieldId): array
    {
        return CampaignManager::$plugin->analytics->getRatingDistribution($campaignId, $siteId, $dateRange, $dateBasis, $fieldId);
    }

    /**
     * A campaign on the site whose invited recipients answered with the
     * given ratings.
     *
     * @param list<int|float> $ratings
     */
    private function seedRatedCampaign(Form $form, int $siteId, array $ratings): Campaign
    {
        $campaign = $this->seedCampaign($form, ['siteId' => $siteId]);
        $this->ownCampaign((int)$campaign->id);
        $now = new DateTime();

        foreach ($ratings as $rating) {
            $this->seedRatedResponse($campaign, $form, $siteId, $now, $now, $rating);
        }

        return $campaign;
    }

    /**
     * A recipient added and invited at one moment who answered with the
     * given rating at another.
     */
    private function seedRatedResponse(Campaign $campaign, Form $form, int $siteId, DateTime $invitedAt, DateTime $respondedAt, int|float $rating): void
    {
        $recipient = $this->seedOwnedRecipient($campaign, $siteId, [
            'dateCreated' => Db::prepareDateForDb($invitedAt),
            'emailSendDate' => Db::prepareDateForDb($invitedAt),
        ]);
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId, [self::RATING_HANDLE => $rating]);
        self::assertSame((int)$submission->id, $this->reloadRecipient($recipient)->submissionId);
        $this->ageSubmission($submission, $respondedAt);
    }

    /**
     * A form with a comment field and one rating field of the given type.
     *
     * @param array<string, mixed> $fieldSettings
     * @return array{Form, int}
     */
    private function seedRatingForm(string $ratingType, int $minValue, int $maxValue, array $fieldSettings = []): array
    {
        $form = $this->seedForm([
            'comment',
            array_merge([
                'type' => Rating::class,
                'handle' => self::RATING_HANDLE,
                'label' => 'Satisfaction',
                'ratingType' => $ratingType,
                'minValue' => $minValue,
                'maxValue' => $maxValue,
            ], $fieldSettings),
        ]);

        $fieldId = null;
        foreach ($form->getFields() as $field) {
            if ($field instanceof Rating) {
                $fieldId = (int)$field->id;
            }
        }
        self::assertNotNull($fieldId, 'The rating field must be part of the form.');

        return [$form, $fieldId];
    }
}
