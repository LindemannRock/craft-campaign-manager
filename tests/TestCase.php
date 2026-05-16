<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests;

use lindemannrock\base\testing\IntegrationTestCase;

/**
 * Base test case for campaign-manager integration tests.
 *
 * The plugin's first testable surface is the pure `PhoneHelper` (recipient
 * input sanitisation, libphonenumber validation, country-mismatch guard) —
 * no DB state, no service stubs, no external cleanup — so this subclass is
 * a thin marker for now. Future tests that touch records / services
 * (Campaign auto-sync, RecipientsService batch shape) should add helpers
 * here.
 *
 * @since 5.11.0
 */
abstract class TestCase extends IntegrationTestCase
{
}
