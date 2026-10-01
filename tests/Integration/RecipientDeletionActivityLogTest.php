<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\controllers\ActivityLogsController;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\FailingActivityLogsService;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Deleting one recipient from the control panel leaves exactly one activity
 * log that keeps the deleted recipient's context and site, and the deletion
 * itself never depends on that log.
 *
 * @since 5.16.0
 */
#[CoversClass(RecipientsController::class)]
#[CoversClass(ActivityLogsController::class)]
final class RecipientDeletionActivityLogTest extends SiteRestrictedUserTestCase
{
    private const DELETE = ['campaignManager:manageRecipients', 'campaignManager:deleteRecipients'];
    private const VIEW = ['campaignManager:viewLogs', 'campaignManager:viewActivityLogs'];

    public function testDeletingARecipientWritesOneLogThatKeepsTheRecipientContext(): void
    {
        [$siteA, $siteB] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $email = 'deleted-' . bin2hex(random_bytes(6)) . '@example.test';
        $recipient = $this->seedOwnedRecipient($campaign, $siteB, [
            'name' => 'Deleted ' . bin2hex(random_bytes(4)),
            'email' => $email,
            'sms' => '+971501234567',
        ]);
        $recipientId = (int)$recipient->id;
        $user = $this->actAsUserRestrictedTo([$siteB], self::DELETE);

        $this->request('POST', [], ['id' => (string)$recipientId]);
        self::assertTrue($this->recipientsController()->actionDeleteFromCp()->data['success']);
        self::assertNull(RecipientRecord::findOne($recipientId));

        self::assertSame(1, $this->ownedActivityLogCount());
        $log = $this->latestOwnedLog('recipient_deleted');
        self::assertNotNull($log);
        self::assertNull($log->recipientId);
        self::assertSame((int)$campaign->id, (int)$log->campaignId);
        self::assertSame((int)$user->id, (int)$log->userId);
        self::assertSame('manual', $log->source);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $log->siteScope);
        self::assertSame([$siteB], $this->logSiteIds((int)$log->id));

        $details = json_decode((string)$log->details, true);
        self::assertSame($recipientId, $details['recipientId']);
        self::assertSame($campaign->title, $details['campaignName']);
        self::assertSame($recipient->name, $details['name']);
        self::assertSame($email, $details['email']);
        self::assertSame('+971501234567', $details['sms']);
        self::assertSame($siteB, (int)$details['siteId']);
        self::assertSame($this->siteName($siteB), $details['siteName']);

        $this->actAsUserRestrictedTo([$siteA], self::VIEW);
        $this->request('GET');
        self::assertStringNotContainsString($email, (string)json_encode($this->activityLogsController()->actionIndex()->data['variables']));

        $this->actAsUserRestrictedTo([$siteB], self::VIEW);
        $this->request('GET');
        self::assertStringContainsString($email, (string)json_encode($this->activityLogsController()->actionIndex()->data['variables']['logs']));

        self::assertSame(1, $this->ownedActivityLogCount());
        self::assertSame(0, $this->ownedQueueJobCount());
    }

    public function testAFailingLogDoesNotUndoOrHideASuccessfulDeletion(): void
    {
        [$siteA] = $this->siteIds(1);
        $campaign = $this->seedOwnedCampaign();
        $recipient = $this->seedOwnedRecipient($campaign, $siteA);
        $this->actAsUserRestrictedTo([$siteA], self::DELETE);
        $logger = new FailingActivityLogsService();
        $this->swapPluginComponent('campaign-manager', 'activityLogs', $logger);

        $this->request('POST', [], ['id' => (string)$recipient->id]);
        $response = $this->recipientsController()->actionDeleteFromCp();

        self::assertTrue($response->data['success']);
        self::assertArrayNotHasKey('error', $response->data);
        self::assertNull(RecipientRecord::findOne($recipient->id));
        self::assertSame(['recipient_deleted'], $logger->attemptedActions);
        self::assertSame(0, $this->ownedActivityLogCount());
    }

    private function siteName(int $siteId): string
    {
        return (string)\Craft::$app->getSites()->getSiteById($siteId)?->name;
    }
}
