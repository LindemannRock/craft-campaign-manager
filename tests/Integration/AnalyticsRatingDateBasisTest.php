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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\services\AnalyticsService;
use lindemannrock\campaignmanager\tests\Support\SeedsAnalyticsEventMatrix;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\formieratingfield\fields\Rating;
use PHPUnit\Framework\Attributes\CoversClass;
use verbb\formie\elements\Form;

/**
 * The ratings tab's "Date based on" filter picks responses by the date it
 * names: "Send activity" keeps the responses of recipients who were invited
 * inside the range, "Response date" keeps the responses submitted inside it.
 * The all-time rating trend covers the requested campaign and site's own
 * retained history, not activity kept for other campaigns or sites.
 *
 * @since 5.16.0
 */
#[CoversClass(AnalyticsService::class)]
final class AnalyticsRatingDateBasisTest extends SiteRestrictedUserTestCase
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

    public function testSendActivityKeepsResponsesOfRecipientsInvitedInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        [$campaign, $fieldId] = $this->seedRatedResponses($siteA);
        $analytics = CampaignManager::$plugin->analytics;
        $campaignId = (int)$campaign->id;

        $stats = $analytics->getRatingStats($campaignId, [$siteA], self::MATRIX_RANGE, 'sent', $fieldId);
        self::assertSame(2, $stats['totalResponses'], 'Two old recipients were invited inside the range; one new recipient was invited after it.');
        self::assertEqualsWithDelta(4.5, $stats['average'], 0.01);

        $breakdown = $analytics->getCampaignRatingBreakdown($campaignId, [$siteA], self::MATRIX_RANGE, 'sent', $fieldId);
        self::assertSame(2, $breakdown['rows'][0]['totalResponses']);

        self::assertCount(2, $analytics->getRawRatingResponses($campaignId, [$siteA], self::MATRIX_RANGE, 'sent', $fieldId)['rows']);
    }

    public function testResponseDateKeepsResponsesSubmittedInTheRange(): void
    {
        [$siteA] = $this->siteIds(1);
        [$campaign, $fieldId] = $this->seedRatedResponses($siteA);
        $analytics = CampaignManager::$plugin->analytics;
        $campaignId = (int)$campaign->id;

        $stats = $analytics->getRatingStats($campaignId, [$siteA], self::MATRIX_RANGE, 'response', $fieldId);
        self::assertSame(2, $stats['totalResponses']);
        self::assertEqualsWithDelta(4.5, $stats['average'], 0.01);
        self::assertCount(2, $analytics->getRawRatingResponses($campaignId, [$siteA], self::MATRIX_RANGE, 'response', $fieldId)['rows']);
    }

    public function testAllTimeKeepsEveryRatedResponseOnEitherBasis(): void
    {
        [$siteA] = $this->siteIds(1);
        [$campaign, $fieldId] = $this->seedRatedResponses($siteA);
        $analytics = CampaignManager::$plugin->analytics;
        $campaignId = (int)$campaign->id;

        self::assertSame(4, $analytics->getRatingStats($campaignId, [$siteA], 'all', 'sent', $fieldId)['totalResponses']);
        self::assertSame(4, $analytics->getRatingStats($campaignId, [$siteA], 'all', 'response', $fieldId)['totalResponses']);
    }

    public function testAllTimeRatingTrendStartsAtTheRequestedCampaignsHistory(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedCampaign($form, ['siteId' => $siteA]);
        $this->ownCampaign((int)$campaign->id);
        $respondedAt = $this->utcDaysAgo(10);
        $this->seedRatedResponseAt($campaign, $form, $siteA, $respondedAt, 5);

        // Another campaign on the same site keeps a far older invitation.
        $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, $this->invitedAt($this->utcDaysAgo(400)));

        $trend = CampaignManager::$plugin->analytics->getRatingTrend((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        $this->assertTrendStartsAt($respondedAt, $trend);
        self::assertSame(5.0, (float)$trend['value'][0]);
        self::assertCount(1, array_filter($trend['value'], static fn($value) => $value !== null));
    }

    public function testAllTimeRatingTrendStartsAtTheRequestedSitesHistory(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedCampaign($form);
        $this->ownCampaign((int)$campaign->id);
        $respondedAt = $this->utcDaysAgo(10);
        $this->seedRatedResponseAt($campaign, $form, $siteA, $respondedAt, 4);

        // The same campaign keeps a far older invitation on a site outside the request.
        $this->seedOwnedRecipient($campaign, $siteB, $this->invitedAt($this->utcDaysAgo(400)));

        $trend = CampaignManager::$plugin->analytics->getRatingTrend((int)$campaign->id, [$siteA], 'all', 'sent', $fieldId);

        $this->assertTrendStartsAt($respondedAt, $trend);
        self::assertSame(4.0, (float)$trend['value'][0]);
    }

    public function testRatingTrendWithoutAnEditableSiteIsEmpty(): void
    {
        [$siteA] = $this->siteIds(1);
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedCampaign($form, ['siteId' => $siteA]);
        $this->ownCampaign((int)$campaign->id);
        $this->seedRatedResponseAt($campaign, $form, $siteA, $this->utcDaysAgo(10), 5);

        $trend = CampaignManager::$plugin->analytics->getRatingTrend((int)$campaign->id, [], 'all', 'sent', $fieldId);

        self::assertSame([], $trend['labels']);
        self::assertSame([], $trend['value']);
    }

    /**
     * Four rated responses: two from recipients invited and answering inside
     * the range (4 and 5 stars), one invited and answering before it (1 star),
     * one added inside the range but invited and answering after it (2 stars).
     *
     * @return array{Campaign, int}
     */
    private function seedRatedResponses(int $siteId): array
    {
        $t = $this->matrixInstants();
        [$form, $fieldId] = $this->seedRatingForm();
        $campaign = $this->seedCampaign($form, ['siteId' => $siteId]);
        $this->ownCampaign((int)$campaign->id);

        $responses = [
            ['created' => 'before', 'sent' => 'inside', 'responded' => 'inside', 'rating' => 4],
            ['created' => 'before', 'sent' => 'inside', 'responded' => 'inside', 'rating' => 5],
            ['created' => 'before', 'sent' => 'before', 'responded' => 'before', 'rating' => 1],
            ['created' => 'inside', 'sent' => 'after', 'responded' => 'after', 'rating' => 2],
        ];

        foreach ($responses as $response) {
            $recipient = $this->seedOwnedRecipient($campaign, $siteId, [
                'dateCreated' => Db::prepareDateForDb($t[$response['created']]),
                'emailSendDate' => Db::prepareDateForDb($t[$response['sent']]),
            ]);
            $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId, [self::RATING_HANDLE => $response['rating']]);
            self::assertSame((int)$submission->id, $this->reloadRecipient($recipient)->submissionId);
            $this->ageSubmission($submission, $t[$response['responded']]);
        }

        return [$campaign, $fieldId];
    }

    /**
     * A form with a comment field and a one-to-five star rating field.
     *
     * @return array{Form, int}
     */
    private function seedRatingForm(): array
    {
        $form = $this->seedForm([
            'comment',
            [
                'type' => Rating::class,
                'handle' => self::RATING_HANDLE,
                'label' => 'Satisfaction',
                'ratingType' => Rating::RATING_TYPE_STAR,
                'minValue' => 1,
                'maxValue' => 5,
            ],
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

    /**
     * A recipient added, invited and answering with the given rating at one moment.
     */
    private function seedRatedResponseAt(Campaign $campaign, Form $form, int $siteId, DateTime $at, int $rating): void
    {
        $recipient = $this->seedOwnedRecipient($campaign, $siteId, $this->invitedAt($at));
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId, [self::RATING_HANDLE => $rating]);
        self::assertSame((int)$submission->id, $this->reloadRecipient($recipient)->submissionId);
        $this->ageSubmission($submission, $at);
    }

    /**
     * @return array<string, string>
     */
    private function invitedAt(DateTime $at): array
    {
        return [
            'dateCreated' => Db::prepareDateForDb($at),
            'emailSendDate' => Db::prepareDateForDb($at),
        ];
    }

    private function utcDaysAgo(int $days): DateTime
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
    }

    /**
     * The trend's daily labels run from the local day of the first in-scope
     * event through today.
     *
     * @param array<string, mixed> $trend
     */
    private function assertTrendStartsAt(DateTime $firstEvent, array $trend): void
    {
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $firstDay = (clone $firstEvent)->setTimezone($tz)->setTime(0, 0, 0);
        $today = (new DateTime('now', $tz))->setTime(0, 0, 0);

        self::assertSame($firstDay->format('M j'), $trend['labels'][0] ?? null, 'The all-time trend starts at the requested scope\'s own history.');
        self::assertCount((int)$firstDay->diff($today)->days + 1, $trend['labels']);
    }
}
