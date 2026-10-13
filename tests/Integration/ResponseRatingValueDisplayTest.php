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
use DOMDocument;
use DOMElement;
use DOMXPath;
use lindemannrock\campaignmanager\controllers\CampaignsController;
use lindemannrock\campaignmanager\controllers\RecipientsController;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use lindemannrock\formieratingfield\fields\Rating;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use verbb\formie\base\Field;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

/**
 * Campaign response details and exports distinguish an unanswered rating from
 * a valid zero, positive or half-step answer.
 *
 * @since 5.16.0
 */
#[CoversClass(CampaignsController::class)]
#[CoversClass(RecipientsController::class)]
final class ResponseRatingValueDisplayTest extends SiteRestrictedUserTestCase
{
    private const PERMISSIONS = [
        'campaignManager:manageCampaigns',
        'campaignManager:manageRecipients',
        'campaignManager:exportRecipients',
    ];

    /** @var array{Form, Campaign, Submission, int}|null */
    private ?array $responseFixture = null;

    public function testFormiePersistsBlankAndNumericRatingsAsDifferentValues(): void
    {
        [$form, , $submission] = $this->responseFixture();
        $values = $this->persistedSubmissionContent($submission);

        self::assertNull($values['unansweredRating']);
        self::assertSame(0.0, $values['zeroRating']);
        self::assertSame(4.0, $values['positiveRating']);
        self::assertSame(3.5, $values['halfRating']);

        $fields = $this->fieldsByHandle($form);
        self::assertTrue($fields['unansweredRating']->isValueEmpty($values['unansweredRating'], $submission));
        self::assertFalse($fields['zeroRating']->isValueEmpty($values['zeroRating'], $submission));
        self::assertFalse($fields['positiveRating']->isValueEmpty($values['positiveRating'], $submission));
        self::assertFalse($fields['halfRating']->isValueEmpty($values['halfRating'], $submission));
    }

    public function testResponseDetailsShowZeroAsAnAnswerAndBlankAsUnanswered(): void
    {
        [, $campaign, , $siteId] = $this->responseFixture();
        $this->actAsUserRestrictedTo([$siteId], self::PERMISSIONS);

        $html = $this->renderResponsesTab($campaign, $siteId);

        $this->assertRenderedValue($html, 'Unanswered rating', 'Not answered', true);
        $this->assertRenderedValue($html, 'Zero rating', '0', false);
        $this->assertRenderedValue($html, 'Positive rating', '4', false);
        $this->assertRenderedValue($html, 'Half rating', '3.5', false);
    }

    public function testResponseExportsPreserveBlankZeroPositiveAndHalfRatings(): void
    {
        [, $campaign, , $siteId] = $this->responseFixture();
        $this->actAsUserRestrictedTo([$siteId], self::PERMISSIONS);

        $csv = $this->exportResponses($campaign, $siteId, 'csv');
        $csvRows = $this->csvRows($csv);
        self::assertCount(2, $csvRows);
        $this->assertExportedValues($csvRows[0], $csvRows[1], "'-");

        $xlsx = $this->exportResponses($campaign, $siteId, 'excel');
        [$headers, $row] = $this->spreadsheetRows($xlsx);
        $this->assertExportedValues($headers, $row, '-');
    }

    /** @return array{Form, Campaign, Submission, int} */
    private function responseFixture(): array
    {
        if ($this->responseFixture !== null) {
            return $this->responseFixture;
        }

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->seedForm([
            $this->ratingField('unansweredRating', 'Unanswered rating'),
            $this->ratingField('zeroRating', 'Zero rating'),
            $this->ratingField('positiveRating', 'Positive rating'),
            $this->ratingField('halfRating', 'Half rating', ['minValue' => 1, 'allowHalfRatings' => true]),
        ]);
        $campaign = $this->seedCampaign($form, ['siteId' => $siteId]);
        $this->ownCampaign((int)$campaign->id);
        $recipient = $this->seedOwnedRecipient($campaign, $siteId);
        $submission = $this->submitForm($form, (string)$recipient->emailInvitationCode, $siteId, [
            'zeroRating' => 0,
            'positiveRating' => 4,
            'halfRating' => 3.5,
        ]);

        self::assertSame((int)$submission->id, $this->reloadRecipient($recipient)->submissionId);

        return $this->responseFixture = [$form, $campaign, $submission, $siteId];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function ratingField(string $handle, string $label, array $overrides = []): array
    {
        return array_merge([
            'type' => Rating::class,
            'handle' => $handle,
            'label' => $label,
            'ratingType' => Rating::RATING_TYPE_STAR,
            'minValue' => 0,
            'maxValue' => 5,
        ], $overrides);
    }

    /** @return array<string, Field> */
    private function fieldsByHandle(Form $form): array
    {
        $fields = [];
        foreach ($form->getFields() as $field) {
            $fields[$field->handle] = $field;
        }

        return $fields;
    }

    private function renderResponsesTab(Campaign $campaign, int $siteId): string
    {
        $this->request('GET', [
            'campaignId' => (string)$campaign->id,
            'tab' => 'responses',
            'site' => $this->siteHandle($siteId),
            'dateRange' => 'all',
        ]);

        return (string)$this->campaignsController()->actionRenderTab()->data['html'];
    }

    private function assertRenderedValue(string $html, string $label, string $expected, bool $empty): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query(sprintf(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' field-item ')][div[contains(concat(' ', normalize-space(@class), ' '), ' field-label ') and normalize-space(.)='%s']]/div[contains(concat(' ', normalize-space(@class), ' '), ' field-value ')]",
            $label,
        ));
        self::assertNotFalse($nodes);
        self::assertCount(1, $nodes, "The {$label} response must be rendered once.");
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);
        self::assertSame($expected, trim($node->textContent));
        self::assertSame($empty, in_array('empty', preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], true));
    }

    private function exportResponses(Campaign $campaign, int $siteId, string $format): string
    {
        $this->request('POST', [], [
            'format' => $format,
            'campaignId' => (string)$campaign->id,
            'siteFilter' => (string)$siteId,
            'dateRange' => 'all',
        ]);

        return (string)$this->recipientsController()->actionExportResponses()->content;
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open the CSV test stream.');
        }

        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** @return array{list<mixed>, list<mixed>} */
    private function spreadsheetRows(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cm-response-rating-');
        if ($path === false) {
            throw new RuntimeException('Failed to create the spreadsheet test path.');
        }

        try {
            if (file_put_contents($path, $xlsx) === false) {
                throw new RuntimeException('Failed to write the spreadsheet test fixture.');
            }
            $spreadsheet = IOFactory::load($path);
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $spreadsheet->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (count($rows) !== 2) {
            throw new RuntimeException('The spreadsheet export did not contain one header and one response row.');
        }

        return [$rows[0], $rows[1]];
    }

    /** @param list<mixed> $headers @param list<mixed> $row */
    private function assertExportedValues(array $headers, array $row, string $unanswered): void
    {
        $values = array_combine(array_map('strval', $headers), $row);
        self::assertNotFalse($values);
        self::assertSame($unanswered, (string)$values['Unanswered rating']);
        self::assertSame('0', (string)$values['Zero rating']);
        self::assertSame('4', (string)$values['Positive rating']);
        self::assertSame('3.5', (string)$values['Half rating']);
    }
}
