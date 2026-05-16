<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\helpers\PhoneHelper;
use lindemannrock\campaignmanager\tests\TestCase;

/**
 * Pins {@see PhoneHelper::validateWithCountry()} — the explicit-country
 * variant the CP add form and CSV import use when the admin has chosen a
 * specific country from the dropdown. The distinguishing contract vs.
 * `validate()` is the **post-parse country-match guard**: if the user
 * pastes `+96597176606` but the country picker says Saudi Arabia, the
 * helper must reject the row rather than silently store a KW number under
 * the SA campaign filter (where it would be invisible to KW recipient
 * queries and counted in the wrong analytics bucket).
 *
 * @since 5.11.0
 */
final class PhoneHelperValidateWithCountryTest extends TestCase
{
    public function testEmptyInputIsValidWithNullE164(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $result = PhoneHelper::validateWithCountry($empty, 'KW');
            self::assertTrue($result['valid']);
            self::assertNull($result['e164']);
            self::assertNull($result['country']);
            self::assertNull($result['error']);
        }
    }

    public function testValidNumberMatchingSelectedCountrySucceeds(): void
    {
        $result = PhoneHelper::validateWithCountry('97176606', 'KW');
        self::assertTrue($result['valid']);
        self::assertSame('+96597176606', $result['e164']);
        self::assertSame('KW', $result['country']);
        self::assertNull($result['error']);
    }

    public function testKuwaitNumberRejectedWhenSaudiArabiaIsSelected(): void
    {
        // +965 unambiguously parses as KW regardless of $defaultRegion, so
        // libphonenumber's detected region is KW but the user picked SA →
        // the country-match guard fires.
        $result = PhoneHelper::validateWithCountry('+96597176606', 'SA');
        self::assertFalse($result['valid']);
        self::assertNull($result['e164']);
        self::assertSame('KW', $result['country']);
        self::assertSame(
            'Phone number does not match selected country (SA)',
            $result['error'],
        );
    }

    public function testCountryCodeIsUppercasedBeforeParsing(): void
    {
        // strtoupper() round-trip: lowercase 'kw' must not surface as
        // "country mismatch" vs the canonical 'KW' libphonenumber returns.
        $result = PhoneHelper::validateWithCountry('97176606', 'kw');
        self::assertTrue($result['valid']);
        self::assertSame('+96597176606', $result['e164']);
        self::assertSame('KW', $result['country']);
    }
}
