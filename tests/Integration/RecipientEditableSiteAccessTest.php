<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use craft\errors\MissingComponentException;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Recipient actions stay inside the sites the acting user may edit.
 *
 * @since 5.16.0
 */
#[CoversClass(RecipientsController::class)]
final class RecipientEditableSiteAccessTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:addRecipients',
        'campaignManager:importRecipients',
        'campaignManager:deleteRecipients',
    ];

    public function testRecipientListShowsAnEditableSite(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $allowed = $this->seedOwnedRecipient($campaign, $siteA);
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('GET', ['site' => $this->siteHandle($siteA), 'dateRange' => 'all']);
        $response = $this->recipientsController()->actionIndex((int)$campaign->id);

        $listed = array_map(
            static fn(RecipientRecord $recipient): int => (int)$recipient->id,
            $response->data['variables']['recipients'],
        );
        self::assertSame([(int)$allowed->id], $listed);
    }

    public function testRecipientPagesDoNotOpenForASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $campaignId = (int)$campaign->id;

        $pages = [
            'recipient list' => fn() => $this->recipientsController()->actionIndex($campaignId),
            'add form' => fn() => $this->recipientsController()->actionAddForm($campaignId),
            'import form' => fn() => $this->recipientsController()->actionImportForm($campaignId),
            'column mapping' => fn() => $this->recipientsController()->actionMap($campaignId),
        ];

        foreach ($pages as $name => $page) {
            foreach ([$this->siteHandle($siteB), 'noSuchSiteHandle'] as $handle) {
                $this->request('GET', ['site' => $handle, 'dateRange' => 'all']);
                $this->assertDenied($page, "The {$name} must not open for site {$handle}.");
            }
        }
    }

    public function testRecipientFormsOpenForAnEditableSite(): void
    {
        [, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteB], self::PERMISSIONS);
        $campaignId = (int)$campaign->id;

        $this->request('GET', ['site' => $this->siteHandle($siteB)]);
        $addForm = $this->recipientsController()->actionAddForm($campaignId);
        self::assertSame($siteB, (int)$addForm->data['variables']['site']->id);

        $this->request('GET', ['site' => $this->siteHandle($siteB)]);
        $importForm = $this->recipientsController()->actionImportForm($campaignId);
        self::assertSame($siteB, (int)$importForm->data['variables']['site']->id);
    }

    public function testColumnMappingAndPreviewReachTheImportSessionForAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $campaignId = (int)$campaign->id;

        // The test runtime has no session, so reaching for the uploaded file
        // fails. Getting that far shows the site was accepted.
        $this->request('GET', ['site' => $this->siteHandle($siteA)]);
        $this->assertReachesImportSession(fn() => $this->recipientsController()->actionMap($campaignId));

        $this->request('POST', ['site' => $this->siteHandle($siteA)], ['campaignId' => (string)$campaignId]);
        $this->assertReachesImportSession(fn() => $this->recipientsController()->actionPreview());
    }

    public function testImportPreviewIsNotBuiltForASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        foreach ([$this->siteHandle($siteB), 'noSuchSiteHandle'] as $handle) {
            $this->request('POST', ['site' => $handle], ['campaignId' => (string)$campaign->id]);
            $this->assertDenied(
                fn() => $this->recipientsController()->actionPreview(),
                "The import preview must not be built for site {$handle}.",
            );
        }
    }

    public function testRecipientIsAddedToAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $email = 'added-' . bin2hex(random_bytes(4)) . '@example.test';

        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteA,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
        ]);

        try {
            $response = $this->recipientsController()->actionAdd();
        } finally {
            $this->ownRecipientsByEmail($email);
        }

        self::assertTrue($response->data['success']);
        self::assertSame(1, $this->recipientCount((int)$campaign->id, $siteA));
    }

    public function testRecipientIsNotAddedToASiteTheUserCannotEdit(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);
        $email = 'crossed-' . bin2hex(random_bytes(4)) . '@example.test';

        $this->request('POST', [], [
            'campaignId' => (string)$campaign->id,
            'siteId' => (string)$siteB,
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => $email,
            'sendInvitation' => '1',
        ]);

        try {
            $this->assertDenied(fn() => $this->recipientsController()->actionAdd());
        } finally {
            $this->ownRecipientsByEmail($email);
        }

        self::assertSame(0, $this->recipientCount((int)$campaign->id, $siteB));
        self::assertSame(0, $this->ownedQueueJobCount());
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testRecipientIsDeletedFromAnEditableSite(): void
    {
        [$siteA] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['id' => (string)$recipient->id]);
        $response = $this->recipientsController()->actionDeleteFromCp();

        self::assertTrue($response->data['success']);
        self::assertNull(RecipientRecord::findOne($recipient->id));
    }

    public function testRecipientOnASiteTheUserCannotEditIsNotDeleted(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $recipient = $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['id' => (string)$recipient->id]);
        $forbidden = $this->recipientsController()->actionDeleteFromCp()->data;

        $this->request('POST', [], ['id' => '987654321']);
        $missing = $this->recipientsController()->actionDeleteFromCp()->data;

        self::assertNotNull(RecipientRecord::findOne($recipient->id));
        self::assertSame($missing, $forbidden, 'A forbidden recipient must look like a missing one.');
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testBulkDeleteRemovesOnlyRecipientsOnEditableSites(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $allowed = $this->seedOwnedRecipient($campaign, $siteA);
        $forbidden = $this->seedOwnedRecipient($campaign, $siteB, ['email' => 'private-person@example.test']);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], [
            'recipientIds' => [(string)$allowed->id, (string)$forbidden->id, '987654321'],
        ]);
        $response = $this->recipientsController()->actionBulkDelete();

        self::assertNull(RecipientRecord::findOne($allowed->id));
        self::assertNotNull(RecipientRecord::findOne($forbidden->id));
        self::assertSame(1, $response->data['count']);
        self::assertCount(2, $response->data['errors']);
        self::assertSame(
            str_replace((string)$forbidden->id, '987654321', $response->data['errors'][0]),
            $response->data['errors'][1],
            'A forbidden recipient must be reported like a missing one.',
        );
        self::assertStringNotContainsString('private-person@example.test', $this->ownedActivityLogText());
    }

    public function testBulkDeleteOfOnlyForbiddenRecipientsChangesNothing(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $forbidden = $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo([$siteA], self::PERMISSIONS);

        $this->request('POST', [], ['recipientIds' => [(string)$forbidden->id]]);
        $response = $this->recipientsController()->actionBulkDelete();

        self::assertFalse($response->data['success']);
        self::assertSame(0, $response->data['count']);
        self::assertNotNull(RecipientRecord::findOne($forbidden->id));
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    public function testUserWithEverySiteKeepsFullRecipientAccess(): void
    {
        $siteIds = array_map('intval', \Craft::$app->getSites()->getAllSiteIds());
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $first = $this->seedOwnedRecipient($campaign, $siteA);
        $second = $this->seedOwnedRecipient($campaign, $siteB);
        $this->actAsUserRestrictedTo($siteIds, self::PERMISSIONS);

        $this->request('GET', ['site' => $this->siteHandle($siteB), 'dateRange' => 'all']);
        $list = $this->recipientsController()->actionIndex((int)$campaign->id);
        self::assertCount(1, $list->data['variables']['recipients']);

        $this->request('POST', [], ['recipientIds' => [(string)$first->id, (string)$second->id]]);
        $response = $this->recipientsController()->actionBulkDelete();

        self::assertSame(2, $response->data['count']);
        self::assertSame([], $response->data['errors']);
    }

    private function assertReachesImportSession(callable $action): void
    {
        try {
            $action();
        } catch (MissingComponentException $e) {
            self::assertStringContainsString('Session', $e->getMessage());

            return;
        }

        self::fail('Expected the action to reach the import session.');
    }

    private function ownRecipientsByEmail(string $email): void
    {
        foreach (RecipientRecord::find()->select('id')->where(['email' => $email])->column() as $recipientId) {
            $this->ownRecipient((int)$recipientId);
        }
    }

    private function recipientCount(int $campaignId, int $siteId): int
    {
        return (int)RecipientRecord::find()->where(['campaignId' => $campaignId, 'siteId' => $siteId])->count();
    }
}
