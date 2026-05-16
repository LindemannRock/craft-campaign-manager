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
 * Pins {@see PhoneHelper::sanitize()} — every recipient phone the plugin
 * sees (CP add form, CSV import, API) flows through this helper before
 * libphonenumber parses it. The dial-prefix conversion and Unicode-invisible
 * stripping are the contract recipients depend on: a paste from a website
 * with hidden right-to-left marks or a number written `00965…` instead of
 * `+965…` must end up in the same canonical shape, otherwise the same
 * recipient gets stored under two different E.164 strings.
 *
 * @since 5.11.0
 */
final class PhoneHelperSanitizeTest extends TestCase
{
    public function testPreservesLeadingPlusAndStripsSeparators(): void
    {
        self::assertSame('+96597176606', PhoneHelper::sanitize('+965 9717 6606'));
        self::assertSame('+96597176606', PhoneHelper::sanitize('+965-9717-6606'));
        self::assertSame('+96597176606', PhoneHelper::sanitize("+965\t9717\n6606"));
    }

    public function testConvertsDoubleZeroDialPrefixToPlus(): void
    {
        self::assertSame('+96597176606', PhoneHelper::sanitize('0096597176606'));
        self::assertSame('+96597176606', PhoneHelper::sanitize('00 965 9717 6606'));
    }

    public function testRemovesBackslashesAndZeroWidthUnicode(): void
    {
        self::assertSame('+96597176606', PhoneHelper::sanitize('+9659717\\6606'));

        // U+200B zero-width space, U+202A LTR embedding, U+FEFF BOM
        $polluted = "+965\u{200B}9717\u{202A}66\u{FEFF}06";
        self::assertSame('+96597176606', PhoneHelper::sanitize($polluted));
    }

    public function testReturnsNullForNullEmptyAndWhitespaceOnly(): void
    {
        self::assertNull(PhoneHelper::sanitize(null));
        self::assertNull(PhoneHelper::sanitize(''));
        self::assertNull(PhoneHelper::sanitize('   '));
    }

    public function testReturnsNullWhenNoDigitsRemain(): void
    {
        // No digits → empty after sanitize → null (callers rely on this:
        // a pasted "(--)" or stray punctuation must not become "" and slip
        // through to libphonenumber's "not a number" branch).
        self::assertNull(PhoneHelper::sanitize('---'));
        self::assertNull(PhoneHelper::sanitize('()'));
    }
}
