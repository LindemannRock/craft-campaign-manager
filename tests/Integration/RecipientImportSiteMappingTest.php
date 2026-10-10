<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\ImportSessionSupport;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A recipient CSV's Site column names the site each row belongs to, by the
 * site's handle exactly as Craft spells it or by its ID. Surrounding
 * whitespace is ignored; anything else must match exactly. A value naming
 * no site, or no value at all, leaves the row on the default site chosen
 * for the import. The site a row resolves to is where the recipient is
 * created, where its invitation batch is queued and what the activity log
 * records, and a row for a site the user may not edit, or where the
 * campaign is disabled, is imported nowhere.
 *
 * @since 5.16.0
 */
#[CoversClass(RecipientsController::class)]
final class RecipientImportSiteMappingTest extends SiteRestrictedUserTestCase
{
    private const IMPORT_PERMISSIONS = [
        'campaignManager:manageRecipients',
        'campaignManager:importRecipients',
    ];

    use ImportSessionSupport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installImportSession();
    }

    protected function cleanupExternalState(): void
    {
        $this->dropImportSession();
        parent::cleanupExternalState();
    }

    public function testAMixedCaseHandleRoutesTheRowToThatSite(): void
    {
        [$default] = $this->siteIds(1);
        $target = $this->mixedCaseSiteId();
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$default, $target], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [
            $this->row($this->siteHandle($target)),
            $this->row('  ' . $this->siteHandle($target) . ' '),
        ]);

        self::assertSame([$target, $target], array_column($import['preview'], 'siteId'), 'The exact handle matches, with or without surrounding whitespace.');
        self::assertCount(2, $import['created']);
        self::assertSame([$target, $target], array_map($this->siteOfRecipient(...), $import['created']));
        $this->assertQueuedBySite($campaign, [$target => $import['created']]);
        $this->assertLogScopes($campaign, [$target], [$target]);
    }

    public function testALowercaseHandleRoutesTheRowToThatSite(): void
    {
        [$default] = $this->siteIds(1);
        $target = $this->lowercaseSiteId();
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$default, $target], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [$this->row($this->siteHandle($target))]);

        self::assertSame([$target], array_column($import['preview'], 'siteId'));
        self::assertSame([$target], array_map($this->siteOfRecipient(...), $import['created']));
        $this->assertQueuedBySite($campaign, [$target => $import['created']]);
        $this->assertLogScopes($campaign, [$target], [$target]);
    }

    public function testANumericSiteIdRoutesTheRowToThatSite(): void
    {
        [$default, $target] = $this->siteIds(2);
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$default, $target], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [$this->row((string)$target)]);

        self::assertSame([$target], array_column($import['preview'], 'siteId'));
        self::assertSame([$target], array_map($this->siteOfRecipient(...), $import['created']));
        $this->assertQueuedBySite($campaign, [$target => $import['created']]);
        $this->assertLogScopes($campaign, [$target], [$target]);
    }

    public function testAnUnknownOrEmptySiteValueLeavesTheRowOnTheDefaultSite(): void
    {
        [$default] = $this->siteIds(1);
        $mixedCase = $this->mixedCaseSiteId();
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$default, $mixedCase], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [
            $this->row('no-such-site'),
            $this->row(''),
            // Handles match exactly: another spelling of a real handle names no site
            $this->row(strtolower($this->siteHandle($mixedCase))),
        ]);

        self::assertSame([$default, $default, $default], array_column($import['preview'], 'siteId'));
        self::assertCount(3, $import['created']);
        self::assertSame([$default, $default, $default], array_map($this->siteOfRecipient(...), $import['created']));
        $this->assertQueuedBySite($campaign, [$default => $import['created']]);
        $this->assertLogScopes($campaign, [$default], [$default]);
    }

    public function testARowForASiteTheUserCannotEditIsImportedNowhere(): void
    {
        [$default] = $this->siteIds(1);
        $forbidden = $this->mixedCaseSiteId();
        $campaign = $this->seedOwnedCampaign();
        $this->actAsUserRestrictedTo([$default], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [$this->row($this->siteHandle($forbidden))]);

        self::assertSame([$forbidden], array_column($import['preview'], 'siteId'), 'The preview resolves the site the row names.');
        self::assertSame([], $import['created'], 'The row is not moved to a site the user may edit.');
        self::assertSame([], $this->queuedJobs((int)$campaign->id));
        self::assertStringContainsString('1 failed.', $import['notice']);
    }

    public function testARowForASiteWhereTheCampaignIsDisabledIsImportedNowhere(): void
    {
        [$default] = $this->siteIds(1);
        $disabled = $this->mixedCaseSiteId();
        $campaign = $this->seedOwnedCampaign();
        $this->disableOwnedCampaignForSite($campaign, $disabled);
        $this->actAsUserRestrictedTo([$default, $disabled], self::IMPORT_PERMISSIONS);

        $import = $this->importCsv($campaign, $default, $default, [
            $this->row($this->siteHandle($disabled)),
            $this->row(''),
        ]);

        self::assertSame([$disabled, $default], array_column($import['preview'], 'siteId'));
        self::assertCount(1, $import['created']);
        self::assertSame([$default], array_map($this->siteOfRecipient(...), $import['created']));
        $this->assertQueuedBySite($campaign, [$default => $import['created']]);
        $this->assertLogScopes($campaign, [$default], [$default]);
        self::assertStringContainsString('1 failed.', $import['notice']);
    }

    /**
     * An enabled site other than the primary one whose handle has upper-case
     * letters, which Craft allows.
     */
    private function mixedCaseSiteId(): int
    {
        return $this->siteIdWhere(static fn(string $handle): bool => $handle !== strtolower($handle), 'a mixed-case site handle');
    }

    private function lowercaseSiteId(): int
    {
        return $this->siteIdWhere(static fn(string $handle): bool => $handle === strtolower($handle), 'a lowercase site handle');
    }

    /**
     * @param callable(string): bool $handleMatches
     */
    private function siteIdWhere(callable $handleMatches, string $description): int
    {
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ((int)$site->id !== $primarySiteId && $site->getEnabled() && $handleMatches((string)$site->handle)) {
                return (int)$site->id;
            }
        }

        self::markTestSkipped("This behavior needs a project with {$description} besides the primary site.");
    }

    /**
     * A CSV row naming the given site.
     *
     * @return array{name: string, email: string, site: string}
     */
    private function row(string $site): array
    {
        return [
            'name' => $this->nextTestMarker('Campaign Manager test ', 'recipient'),
            'email' => 'imported-' . bin2hex(random_bytes(4)) . '@example.test',
            'site' => $site,
        ];
    }

    /**
     * Upload, map, preview and import a CSV with Name, Email and Site columns,
     * with sending switched on.
     *
     * @param list<array{name: string, email: string, site: string}> $rows
     * @return array{preview: list<array<string, mixed>>, created: list<int>, notice: string}
     */
    private function importCsv(Campaign $campaign, int $startedOnSiteId, int $defaultSiteId, array $rows): array
    {
        $campaignId = (int)$campaign->id;
        $csv = "Name,Email,Site\n";
        foreach ($rows as $row) {
            $csv .= "{$row['name']},{$row['email']},{$row['site']}\n";
        }

        $parsed = $this->parseOwnedCsv($csv);
        $this->session()->set('recipient-import', [
            'headers' => $parsed['headers'],
            'allRows' => $parsed['allRows'],
            'rowCount' => $parsed['rowCount'],
            'campaignId' => $campaignId,
            'queueSending' => true,
            'filename' => 'recipients.csv',
        ]);

        $startedOn = $this->siteHandle($startedOnSiteId);
        $this->request('GET', ['site' => $startedOn]);
        $map = $this->withSession(fn() => $this->recipientsController()->actionMap($campaignId));
        self::assertSame(['Name', 'Email', 'Site'], $map->data['variables']['headers']);

        $this->request('POST', ['site' => $startedOn], [
            'campaignId' => (string)$campaignId,
            'queueSending' => '1',
            'mapping' => ['0' => 'name', '1' => 'email', '2' => 'site'],
            'defaultSiteId' => (string)$defaultSiteId,
            'phoneCountry' => '',
        ]);
        $preview = $this->withSession(fn() => $this->recipientsController()->actionPreview());
        self::assertSame(count($rows), $preview->data['variables']['summary']['validRows'], 'Every row is valid; only its site is in question.');
        $validRows = $preview->data['variables']['validRows'];

        $before = $this->campaignRecipientIds($campaignId);
        $this->request('POST', ['site' => $startedOn], ['campaignId' => (string)$campaignId]);
        $created = [];
        try {
            $this->withSession(fn() => $this->recipientsController()->actionImport());
        } finally {
            $created = array_values(array_diff($this->campaignRecipientIds($campaignId), $before));
            foreach ($created as $recipientId) {
                $this->ownRecipient($recipientId);
            }
        }

        return [
            'preview' => $validRows,
            'created' => $created,
            'notice' => (string)$this->session()->getNotice(),
        ];
    }

    /**
     * @return list<int>
     */
    private function campaignRecipientIds(int $campaignId): array
    {
        return array_map('intval', RecipientRecord::find()
            ->select('id')
            ->where(['campaignId' => $campaignId])
            ->orderBy('id')
            ->column());
    }

    private function siteOfRecipient(int $recipientId): int
    {
        return (int)RecipientRecord::find()->select('siteId')->where(['id' => $recipientId])->scalar();
    }

    /**
     * Assert exactly which recipients were queued for which site.
     *
     * @param array<int, list<int>> $expected
     */
    private function assertQueuedBySite(Campaign $campaign, array $expected): void
    {
        $bySite = [];
        foreach ($this->queuedJobs((int)$campaign->id) as $job) {
            self::assertInstanceOf(SendBatchJob::class, $job);
            self::assertSame((int)$campaign->id, $job->campaignId);
            foreach ($job->recipientIds as $recipientId) {
                $bySite[$job->siteId][] = $recipientId;
            }
        }
        ksort($bySite);
        ksort($expected);

        self::assertSame($expected, $bySite);
    }

    /**
     * @param list<int> $importedSiteIds
     * @param list<int> $queuedSiteIds
     */
    private function assertLogScopes(Campaign $campaign, array $importedSiteIds, array $queuedSiteIds): void
    {
        sort($importedSiteIds);
        sort($queuedSiteIds);
        $imported = $this->latestOwnedLog('recipients_imported');
        self::assertNotNull($imported);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $imported->siteScope);
        self::assertSame($importedSiteIds, $this->logSiteIds((int)$imported->id));

        $queued = $this->latestOwnedLog('campaign_invitations_queued');
        self::assertNotNull($queued);
        self::assertSame(ActivityLogRecord::SCOPE_SITES, $queued->siteScope);
        self::assertSame($queuedSiteIds, $this->logSiteIds((int)$queued->id));
    }
}
