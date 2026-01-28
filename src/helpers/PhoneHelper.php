<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\helpers;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use lindemannrock\campaignmanager\CampaignManager;

/**
 * Phone Helper
 *
 * Provides international phone number validation and formatting using libphonenumber.
 * All phone numbers are stored in E.164 format (+96597176606).
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class PhoneHelper
{
    /**
     * Validate and format a phone number to E.164 format
     *
     * @param string|null $phone Raw phone number
     * @param string|null $defaultRegion Default country code (e.g., 'KW' for Kuwait). If null, uses plugin settings.
     * @return array{valid: bool, e164: string|null, error: string|null, country: string|null}
     * @since 5.0.0
     */
    public static function validate(?string $phone, ?string $defaultRegion = null): array
    {
        if ($phone === null || trim($phone) === '') {
            return [
                'valid' => true, // Empty is valid (phone is optional)
                'e164' => null,
                'error' => null,
                'country' => null,
            ];
        }

        // Get default region from settings if not provided
        if ($defaultRegion === null) {
            $defaultRegion = self::getDefaultRegion();
        }

        // Clean the input
        $phone = self::sanitize($phone);

        if ($phone === null || $phone === '') {
            return [
                'valid' => false,
                'e164' => null,
                'error' => 'Phone number is empty after sanitization',
                'country' => null,
            ];
        }

        // Check for letters (common error)
        if (preg_match('/[a-zA-Z]/', $phone)) {
            return [
                'valid' => false,
                'e164' => null,
                'error' => 'Phone number contains letters',
                'country' => null,
            ];
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $numberProto = $phoneUtil->parse($phone, $defaultRegion);

            if (!$phoneUtil->isValidNumber($numberProto)) {
                return [
                    'valid' => false,
                    'e164' => null,
                    'error' => 'Invalid phone number for the detected country',
                    'country' => null,
                ];
            }

            $e164 = $phoneUtil->format($numberProto, PhoneNumberFormat::E164);
            $country = $phoneUtil->getRegionCodeForNumber($numberProto);

            return [
                'valid' => true,
                'e164' => $e164, // e.g., +96597176606
                'error' => null,
                'country' => $country, // e.g., 'KW'
            ];
        } catch (NumberParseException $e) {
            return [
                'valid' => false,
                'e164' => null,
                'error' => match ($e->getErrorType()) {
                    NumberParseException::INVALID_COUNTRY_CODE => 'Invalid country code',
                    NumberParseException::NOT_A_NUMBER => 'Not a valid phone number',
                    NumberParseException::TOO_SHORT_AFTER_IDD => 'Phone number too short',
                    NumberParseException::TOO_SHORT_NSN => 'Phone number too short',
                    NumberParseException::TOO_LONG => 'Phone number too long',
                    default => 'Invalid phone number format',
                },
                'country' => null,
            ];
        }
    }

    /**
     * Check if a phone number is valid
     *
     * @param string|null $phone Phone number to validate
     * @param string|null $defaultRegion Default country code
     * @since 5.0.0
     */
    public static function isValid(?string $phone, ?string $defaultRegion = null): bool
    {
        return self::validate($phone, $defaultRegion)['valid'];
    }

    /**
     * Format phone number to E.164 format (+96597176606)
     *
     * @param string|null $phone Phone number to format
     * @param string|null $defaultRegion Default country code
     * @since 5.0.0
     */
    public static function toE164(?string $phone, ?string $defaultRegion = null): ?string
    {
        return self::validate($phone, $defaultRegion)['e164'];
    }

    /**
     * Get human-readable format for display (+965 9717 6606)
     *
     * @param string|null $phone Phone number to format
     * @param string|null $defaultRegion Default country code
     * @since 5.0.0
     */
    public static function formatForDisplay(?string $phone, ?string $defaultRegion = null): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        if ($defaultRegion === null) {
            $defaultRegion = self::getDefaultRegion();
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            $numberProto = $phoneUtil->parse($phone, $defaultRegion);
            return $phoneUtil->format($numberProto, PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $phone; // Return original if can't parse
        }
    }

    /**
     * Sanitize a phone number by removing invalid characters
     *
     * Removes:
     * - Whitespace (spaces, tabs, newlines)
     * - Hidden Unicode characters (zero-width, RTL/LTR marks)
     * - Backslashes
     * - Keeps + at the start and digits
     *
     * IMPORTANT: This does NOT auto-detect country codes. Use validateWithCountry()
     * when you have an explicit country code selection from the user.
     *
     * @param string|null $phone Phone number to sanitize
     * @since 5.0.0
     */
    public static function sanitize(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        // Remove whitespace (spaces, tabs, newlines)
        $phone = preg_replace('/\s+/', '', $phone);

        // Remove hidden Unicode characters:
        // - Zero-width spaces (U+200B-U+200D)
        // - Zero-width no-break space / BOM (U+FEFF)
        // - Bidirectional text control (U+202A-U+202E)
        // - Word joiner (U+2060)
        // - Invisible separator (U+2063)
        $phone = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}\x{2063}]+/u', '', $phone);

        // Remove backslashes
        $phone = str_replace('\\', '', $phone);

        // Preserve + at the start if present
        $hasPlus = str_starts_with($phone, '+');

        // Check for 00 international prefix (common in many countries)
        $hasDoubleZero = str_starts_with($phone, '00');

        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        if ($phone === '' || $phone === null) {
            return null;
        }

        // Remove leading 00 (international dialing prefix) and treat as +
        if ($hasDoubleZero && strlen($phone) > 2) {
            $phone = substr($phone, 2);
            $hasPlus = true;
        }

        // Re-add + if it was at the start (or had 00 prefix)
        if ($hasPlus && !empty($phone)) {
            $phone = '+' . $phone;
        }

        return $phone === '' ? null : $phone;
    }

    /**
     * Get the country code from a phone number
     *
     * @param string|null $phone Phone number
     * @param string|null $defaultRegion Default country code
     * @since 5.0.0
     */
    public static function getCountry(?string $phone, ?string $defaultRegion = null): ?string
    {
        return self::validate($phone, $defaultRegion)['country'];
    }

    /**
     * Get the default region from plugin settings
     *
     * @param string|null $providerHandle Optional provider handle to get country from
     * @since 5.0.0
     */
    public static function getDefaultRegion(?string $providerHandle = null): string
    {
        if (CampaignManager::$plugin !== null) {
            // Get country from provider's allowed countries (falls back to KW if not available)
            return CampaignManager::$plugin->sms->getDefaultCountryForProvider($providerHandle);
        }

        return 'AE';
    }

    /**
     * Validate phone with provider context
     *
     * Uses the provider's allowed countries to determine the default region.
     *
     * @param string|null $phone Raw phone number
     * @param string|null $providerHandle Provider handle for country lookup
     * @return array{valid: bool, e164: string|null, error: string|null, country: string|null}
     * @since 5.0.0
     */
    public static function validateWithProvider(?string $phone, ?string $providerHandle = null): array
    {
        $defaultRegion = self::getDefaultRegion($providerHandle);
        return self::validate($phone, $defaultRegion);
    }

    /**
     * Validate and format phone number with explicit country code
     *
     * This is the PREFERRED method when you have an explicit country selection from the user.
     * It ensures the phone number is formatted for the selected country without any guessing.
     *
     * @param string|null $phone Raw phone number (local or with country code)
     * @param string $countryCode ISO 3166-1 alpha-2 country code (e.g., 'KW', 'AE')
     * @return array{valid: bool, e164: string|null, error: string|null, country: string|null}
     * @since 5.1.0
     */
    public static function validateWithCountry(?string $phone, string $countryCode): array
    {
        if ($phone === null || trim($phone) === '') {
            return [
                'valid' => true, // Empty is valid (phone is optional)
                'e164' => null,
                'error' => null,
                'country' => null,
            ];
        }

        // Clean the input
        $phone = self::sanitize($phone);

        if ($phone === null || $phone === '') {
            return [
                'valid' => false,
                'e164' => null,
                'error' => 'Phone number is empty after sanitization',
                'country' => null,
            ];
        }

        // Check for letters (common error)
        if (preg_match('/[a-zA-Z]/', $phone)) {
            return [
                'valid' => false,
                'e164' => null,
                'error' => 'Phone number contains letters',
                'country' => null,
            ];
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            // Parse with the explicit country code
            $numberProto = $phoneUtil->parse($phone, strtoupper($countryCode));

            if (!$phoneUtil->isValidNumber($numberProto)) {
                return [
                    'valid' => false,
                    'e164' => null,
                    'error' => 'Invalid phone number for ' . strtoupper($countryCode),
                    'country' => null,
                ];
            }

            // Verify the parsed number belongs to the selected country
            $detectedCountry = $phoneUtil->getRegionCodeForNumber($numberProto);
            if ($detectedCountry !== strtoupper($countryCode)) {
                return [
                    'valid' => false,
                    'e164' => null,
                    'error' => 'Phone number does not match selected country (' . strtoupper($countryCode) . ')',
                    'country' => $detectedCountry,
                ];
            }

            $e164 = $phoneUtil->format($numberProto, PhoneNumberFormat::E164);

            return [
                'valid' => true,
                'e164' => $e164,
                'error' => null,
                'country' => $detectedCountry,
            ];
        } catch (NumberParseException $e) {
            return [
                'valid' => false,
                'e164' => null,
                'error' => match ($e->getErrorType()) {
                    NumberParseException::INVALID_COUNTRY_CODE => 'Invalid country code',
                    NumberParseException::NOT_A_NUMBER => 'Not a valid phone number',
                    NumberParseException::TOO_SHORT_AFTER_IDD => 'Phone number too short',
                    NumberParseException::TOO_SHORT_NSN => 'Phone number too short',
                    NumberParseException::TOO_LONG => 'Phone number too long',
                    default => 'Invalid phone number format',
                },
                'country' => null,
            ];
        }
    }
}
