<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use craft\helpers\Db;
use DateTime;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\formieratingfield\fields\Rating;
use verbb\formie\elements\Form;

/**
 * Forms with a rating field and recipients who answered them, for the
 * rating analytics tests.
 *
 * Every form, submission, campaign and recipient is owned by the using
 * test case the way its other fixtures are. Used by test classes that
 * extend {@see SiteRestrictedUserTestCase}.
 *
 * @since 5.16.0
 */
trait SeedsRatedResponses
{
    protected const RATING_HANDLE = 'satisfaction';

    /**
     * A form with a comment field and one rating field of the given type.
     *
     * @param array<string, mixed> $fieldSettings
     * @return array{Form, int}
     */
    protected function seedRatingForm(string $ratingType = Rating::RATING_TYPE_STAR, int $minValue = 1, int $maxValue = 5, array $fieldSettings = []): array
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

    /**
     * A campaign on the site whose invited recipients answered with the
     * given ratings.
     *
     * @param list<int|float> $ratings
     */
    protected function seedRatedCampaign(Form $form, int $siteId, array $ratings): Campaign
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
    protected function seedRatedResponse(Campaign $campaign, Form $form, int $siteId, DateTime $invitedAt, DateTime $respondedAt, int|float $rating): void
    {
        $recipient = $this->seedOwnedRecipient($campaign, $siteId, [
            'dateCreated' => Db::prepareDateForDb($invitedAt),
            'emailSendDate' => Db::prepareDateForDb($invitedAt),
        ]);
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId, [self::RATING_HANDLE => $rating]);
        self::assertSame((int)$submission->id, $this->reloadRecipient($recipient)->submissionId);
        $this->ageSubmission($submission, $respondedAt);
    }
}
