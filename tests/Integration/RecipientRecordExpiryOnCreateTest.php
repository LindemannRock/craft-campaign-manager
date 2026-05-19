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
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\TestCase;
use ReflectionProperty;

/**
 * Pins {@see RecipientRecord::beforeSave()} for the invitation-expiry path.
 *
 * Two regressions covered:
 *
 * 1. **Silent feature breakage.** The previous implementation gated the
 *    expiry-period read behind `method_exists($campaign, 'getInvitationExpiryPeriod')`.
 *    That getter lives on `CampaignBehavior`, which PHP's `method_exists()`
 *    can't see (behavior methods are dispatched via `__call`). The check
 *    always returned false and the entire branch was dead — admins set an
 *    expiry on the campaign and recipients silently saved with no expiry
 *    date. The fix accesses `invitationExpiryPeriod` as a public property
 *    on the `Campaign` element directly, which is what `CampaignQuery`
 *    actually hydrates.
 *
 * 2. **Crash on malformed legacy data.** Now that the expiry-read path
 *    actually runs, legacy campaigns with malformed period strings (from
 *    pre-5.11 tampered POSTs, direct DB writes, or migrations from other
 *    installs) would crash `new DateInterval()` and break recipient
 *    creation entirely. The fix wraps the DateInterval call so a malformed
 *    period logs a warning and the recipient saves with no expiry,
 *    matching the previous (broken) behavior for the bad-data case.
 *
 * The tests inject a stub `Campaign` via reflection on `_campaign` so we
 * exercise `beforeSave()` without persisting a Campaign element + Formie
 * Form to the DB.
 *
 * @since 5.11.0
 */
final class RecipientRecordExpiryOnCreateTest extends TestCase
{
    public function testBeforeSaveSetsExpiryFromValidCampaignPeriod(): void
    {
        $recipient = $this->makeRecipientWithCampaignPeriod('P1D');

        $recipient->beforeSave(true);

        self::assertNotNull(
            $recipient->invitationExpiryDate,
            'A campaign with a valid expiry period should populate the recipient expiry date.',
        );
        self::assertGreaterThan(
            time(),
            $recipient->invitationExpiryDate->getTimestamp(),
            'Expiry date should be in the future for a forward-looking period.',
        );
    }

    public function testBeforeSaveLeavesExpiryNullForMalformedCampaignPeriod(): void
    {
        $recipient = $this->makeRecipientWithCampaignPeriod('P1foo');

        $result = $recipient->beforeSave(true);

        // Critical: parent::beforeSave() must still return true — recipient
        // save flow continues, no exception escapes from the malformed data.
        self::assertTrue($result, 'beforeSave() must not propagate the DateInterval exception.');
        self::assertNull(
            $recipient->invitationExpiryDate,
            'A campaign with a malformed expiry period should leave the recipient expiry null.',
        );
    }

    public function testBeforeSaveLeavesExpiryNullWhenCampaignHasNoPeriod(): void
    {
        $recipient = $this->makeRecipientWithCampaignPeriod(null);

        $recipient->beforeSave(true);

        self::assertNull($recipient->invitationExpiryDate);
    }

    private function makeRecipientWithCampaignPeriod(?string $period): RecipientRecord
    {
        $campaign = new Campaign();
        $campaign->invitationExpiryPeriod = $period;

        $recipient = new RecipientRecord();
        $recipient->campaignId = 999999; // never resolved — _campaign is pre-set below
        $recipient->siteId = 1;
        $recipient->name = '__campaignmanager_test_recipient';

        // Pre-populate the lazy campaign cache so getCampaign() returns our
        // stub instead of calling Craft::$app->elements->getElementById().
        $prop = new ReflectionProperty($recipient, '_campaign');
        $prop->setAccessible(true);
        $prop->setValue($recipient, $campaign);

        return $recipient;
    }
}
