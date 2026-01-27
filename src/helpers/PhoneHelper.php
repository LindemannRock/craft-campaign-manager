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
     * @param string|null $phone Phone number to sanitize
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

        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Re-add + if it was at the start
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
     */
    public static function getCountry(?string $phone, ?string $defaultRegion = null): ?string
    {
        return self::validate($phone, $defaultRegion)['country'];
    }

    /**
     * Get the default region from plugin settings
     */
    public static function getDefaultRegion(): string
    {
        if (CampaignManager::$plugin !== null) {
            $settings = CampaignManager::$plugin->getSettings();
            return $settings->defaultCountryCode ?? 'KW';
        }

        return 'KW';
    }
}
