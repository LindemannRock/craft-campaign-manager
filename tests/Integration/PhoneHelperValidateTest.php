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
 * Pins {@see PhoneHelper::validate()} — the validation layer recipients
 * controllers + CSV import call to decide whether a row is acceptable and
 * what to store as the canonical E.164 string. Empty input is intentionally
 * "valid" (phone is optional on a recipient) so the result-shape contract
 * matters as much as the boolean: callers read `e164` regardless of `valid`
 * to decide what to persist.
 *
 * `$defaultRegion` is supplied explicitly in every test so the suite doesn't
 * couple to whatever country `PhoneHelper::getDefaultRegion()` returns from
 * the live SMS Manager provider state.
 *
 * @since 5.11.0
 */
final class PhoneHelperValidateTest extends TestCase
{
    public function testEmptyInputIsValidWithNullE164(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $result = PhoneHelper::validate($empty, 'KW');
            self::assertTrue($result['valid']);
            self::assertNull($result['e164']);
            self::assertNull($result['country']);
            self::assertNull($result['error']);
        }
    }

    public function testValidKuwaitNumberReturnsE164AndCountry(): void
    {
        $result = PhoneHelper::validate('97176606', 'KW');
        self::assertTrue($result['valid']);
        self::assertSame('+96597176606', $result['e164']);
        self::assertSame('KW', $result['country']);
        self::assertNull($result['error']);
    }

    public function testE164InputReturnsItselfInE164Form(): void
    {
        // Already-canonical input round-trips unchanged — pins the contract
        // CSV re-imports rely on (a previously-stored E.164 must validate
        // without rewriting).
        $result = PhoneHelper::validate('+96597176606', 'KW');
        self::assertTrue($result['valid']);
        self::assertSame('+96597176606', $result['e164']);
    }

    public function testTooShortNumberIsRejected(): void
    {
        // libphonenumber rejects this as too short for KW. The exact error
        // string varies between the parse-exception branch (NumberParseException
        // → "Phone number too short") and the `isValidNumber()` branch
        // ("Invalid phone number for the detected country") — callers don't
        // distinguish, so the assertion is just "not valid, no e164, some
        // error".
        $result = PhoneHelper::validate('12', 'KW');
        self::assertFalse($result['valid']);
        self::assertNull($result['e164']);
        self::assertNotNull($result['error']);
    }

    public function testInputContainingLettersIsRejectedWithSpecificError(): void
    {
        // Regression: the letter check used to run after sanitize() (which
        // strips non-digits), making it unreachable. Users got the generic
        // "Invalid phone number" instead of the more actionable "contains
        // letters". Pins the ordering so the specific error reaches callers.
        $result = PhoneHelper::validate('555-PHONE', 'KW');
        self::assertFalse($result['valid']);
        self::assertNull($result['e164']);
        self::assertSame('Phone number contains letters', $result['error']);
    }

    public function testInputThatIsAllLettersIsRejectedWithSpecificError(): void
    {
        $result = PhoneHelper::validate('callmemaybe', 'KW');
        self::assertFalse($result['valid']);
        self::assertNull($result['e164']);
        self::assertSame('Phone number contains letters', $result['error']);
    }
}
