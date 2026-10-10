<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins {@see Campaign::isValidDurationPeriod()} and the model-level `match`
 * rule on `invitationDelayPeriod` / `invitationExpiryPeriod`.
 *
 * Background: until 5.11.0, the campaign edit form posted a raw unit value
 * (`H`/`D`/`W`/`M`) that was interpolated into the stored ISO 8601 period
 * without validation. POST tampering could produce strings like `P1foo`
 * which the model would happily save and which would later crash `new
 * DateInterval()` when a recipient was created on that campaign. The fix
 * is a single-source-of-truth allowlist anchored on the Campaign element
 * and enforced as a validation rule so errors surface in the edit form.
 *
 * A value is valid only when it has an offered unit and PHP can read it as
 * a duration: a run of digits the parser refuses is rejected too, so a
 * stored period can always be read where it is used.
 *
 * @since 5.11.0
 */
final class CampaignDurationPeriodValidationTest extends TestCase
{
    #[DataProvider('validPeriodProvider')]
    public function testIsValidDurationPeriodAcceptsAllowedPeriods(string $period): void
    {
        self::assertTrue(Campaign::isValidDurationPeriod($period));
    }

    public static function validPeriodProvider(): array
    {
        return [
            'hours_one' => ['PT1H'],
            'hours_many' => ['PT72H'],
            'days_one' => ['P1D'],
            'days_many' => ['P14D'],
            'weeks_one' => ['P1W'],
            'weeks_many' => ['P4W'],
            'months_one' => ['P1M'],
            'months_many' => ['P6M'],
        ];
    }

    #[DataProvider('invalidPeriodProvider')]
    public function testIsValidDurationPeriodRejectsDisallowedPeriods(string $period): void
    {
        self::assertFalse(Campaign::isValidDurationPeriod($period));
    }

    public static function invalidPeriodProvider(): array
    {
        return [
            'years_not_offered' => ['P1Y'],
            'seconds_not_offered' => ['PT30S'],
            'minutes_not_offered' => ['PT15M'],
            'tampered_unit' => ['P1foo'],
            'sql_attempt' => ['P1; DROP TABLE'],
            'lowercase_unit' => ['P1d'],
            'missing_p_prefix' => ['1D'],
            'leading_whitespace' => [' P1D'],
            'trailing_whitespace' => ['P1D '],
            'no_digits' => ['PXD'],
            'extra_suffix' => ['P1Dfoo'],
            'hours_php_cannot_read' => ['PT213213213213123H'],
            'days_php_cannot_read' => ['P9999999999999D'],
        ];
    }

    public function testIsValidDurationPeriodTreatsEmptyAsValid(): void
    {
        // null and '' represent "no delay / no expiry" — natural states the
        // edit form produces when the user clears the value. They must pass.
        self::assertTrue(Campaign::isValidDurationPeriod(null));
        self::assertTrue(Campaign::isValidDurationPeriod(''));
    }

    public function testValidationRuleRejectsMalformedDelayPeriod(): void
    {
        $campaign = new Campaign();
        $campaign->invitationDelayPeriod = 'P1foo';

        $campaign->validate(['invitationDelayPeriod']);

        self::assertNotEmpty(
            $campaign->getErrors('invitationDelayPeriod'),
            'Malformed delay period should fail the match rule.',
        );
    }

    public function testValidationRuleRejectsADelayPeriodPhpCannotRead(): void
    {
        $campaign = new Campaign();
        $campaign->invitationDelayPeriod = 'PT213213213213123H';

        $campaign->validate(['invitationDelayPeriod']);

        self::assertNotEmpty(
            $campaign->getErrors('invitationDelayPeriod'),
            'A delay period PHP cannot read as a duration must not be accepted.',
        );
    }

    public function testValidationRuleRejectsAnExpiryPeriodPhpCannotRead(): void
    {
        $campaign = new Campaign();
        $campaign->invitationExpiryPeriod = 'PT213213213213123H';

        $campaign->validate(['invitationExpiryPeriod']);

        self::assertNotEmpty(
            $campaign->getErrors('invitationExpiryPeriod'),
            'An expiry period PHP cannot read as a duration must not be accepted.',
        );
    }

    public function testValidationRuleRejectsMalformedExpiryPeriod(): void
    {
        $campaign = new Campaign();
        $campaign->invitationExpiryPeriod = 'P1Y'; // year not in UI

        $campaign->validate(['invitationExpiryPeriod']);

        self::assertNotEmpty(
            $campaign->getErrors('invitationExpiryPeriod'),
            'Year-based expiry period should fail the match rule (UI offers H/D/W/M only).',
        );
    }

    public function testValidationRuleAcceptsValidPeriods(): void
    {
        $campaign = new Campaign();
        $campaign->invitationDelayPeriod = 'PT2H';
        $campaign->invitationExpiryPeriod = 'P30D';

        $campaign->validate(['invitationDelayPeriod', 'invitationExpiryPeriod']);

        self::assertEmpty($campaign->getErrors('invitationDelayPeriod'));
        self::assertEmpty($campaign->getErrors('invitationExpiryPeriod'));
    }

    public function testValidationRuleSkipsEmptyPeriods(): void
    {
        // Yii's `match` validator defaults to skipOnEmpty=true — null/empty
        // values are "no period set", which is a valid state for both fields.
        $campaign = new Campaign();
        $campaign->invitationDelayPeriod = null;
        $campaign->invitationExpiryPeriod = '';

        $campaign->validate(['invitationDelayPeriod', 'invitationExpiryPeriod']);

        self::assertEmpty($campaign->getErrors('invitationDelayPeriod'));
        self::assertEmpty($campaign->getErrors('invitationExpiryPeriod'));
    }
}
