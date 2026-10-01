<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\InvitationRequest;
use RuntimeException;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\fields\SingleLineText;
use verbb\formie\Formie;
use verbb\formie\models\FieldLayout;
use verbb\formie\services\Fields as FormieFields;

/**
 * Base test case for campaign-manager integration tests.
 *
 * Tests run against the live development database without transactional
 * rollback, so every persisted fixture is recorded by its exact identity when
 * it is created and removed by that identity during teardown:
 *
 *  - Formie forms with their layout, page, row and field rows
 *  - Formie submissions
 *  - campaigns with their per-site content rows
 *  - recipients
 *  - the request component, when an invitation request was installed
 *
 * Subclasses overriding `setUp()` or `tearDown()` must call the parent.
 *
 * @since 5.11.0
 */
abstract class TestCase extends IntegrationTestCase
{
    /**
     * @var array<int, array{
     *     formId: int,
     *     elementIds: list<int>,
     *     submissionIds: list<int>,
     *     layoutIds: list<int>,
     *     pageIds: list<int>,
     *     rowIds: list<int>,
     *     fieldIds: list<int>
     * }>
     */
    private array $formFixtures = [];

    /**
     * @var list<int>
     */
    private array $campaignIds = [];

    /**
     * @var list<int>
     */
    private array $recipientIds = [];

    private ?object $originalRequest = null;

    protected function cleanupExternalState(): void
    {
        // Every step runs even when an earlier one fails, so one leftover
        // never strands the rest of the fixtures.
        $failure = null;

        foreach ([
            $this->restoreRequest(...),
            $this->cleanupRecipients(...),
            $this->cleanupCampaigns(...),
            $this->cleanupFormFixtures(...),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    protected function saveTestElement(
        ElementInterface $element,
        bool $runValidation = false,
        bool $propagate = true,
        bool $updateSearchIndex = true,
    ): ElementInterface {
        $saved = parent::saveTestElement($element, $runValidation, $propagate, $updateSearchIndex);

        if ($element instanceof Form) {
            $this->trackForm($element);
        } elseif ($element instanceof Submission) {
            $this->trackSubmission($element);
        } elseif ($element instanceof Campaign) {
            $this->campaignIds[] = (int)$element->id;
        }

        return $saved;
    }

    /**
     * Save a Formie form whose single page contains one text field per handle.
     *
     * @param list<string> $fieldHandles
     */
    protected function seedForm(array $fieldHandles): Form
    {
        $fields = [];
        foreach ($fieldHandles as $handle) {
            $fields[] = [
                'type' => SingleLineText::class,
                'handle' => $handle,
                'label' => ucfirst($handle),
            ];
        }

        $layout = new FieldLayout();
        $layout->setPages([[
            'label' => 'Page 1',
            'rows' => array_map(static fn(array $field): array => ['fields' => [$field]], $fields),
        ]]);

        $form = new Form();
        $form->title = $this->nextTestMarker('Campaign Manager test ', 'form');
        $form->handle = $this->nextTestMarker('campaignManagerTest', 'form');
        $form->setFormLayout($layout);
        $this->saveTestElement($form, false, true, false);

        return $form;
    }

    /**
     * Save a campaign assigned to the given form.
     *
     * @param array<string, mixed> $attributes
     */
    protected function seedCampaign(Form $form, array $attributes = []): Campaign
    {
        $campaign = new Campaign();
        $campaign->title = $this->nextTestMarker('Campaign Manager test ', 'campaign');
        $campaign->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $campaign->formId = (int)$form->id;

        foreach ($attributes as $name => $value) {
            $campaign->{$name} = $value;
        }

        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * Save a recipient for the campaign.
     *
     * @param array<string, mixed> $attributes
     */
    protected function seedRecipient(Campaign $campaign, array $attributes = []): RecipientRecord
    {
        $recipient = new RecipientRecord();
        $recipient->campaignId = (int)$campaign->id;
        $recipient->siteId = (int)$campaign->siteId;
        $recipient->name = $this->nextTestMarker('Campaign Manager test ', 'recipient');
        $recipient->email = 'recipient@example.test';

        foreach ($attributes as $name => $value) {
            $recipient->{$name} = $value;
        }

        if (!$recipient->save(false) || $recipient->id === null) {
            throw new RuntimeException('Test recipient failed to save.');
        }

        $this->recipientIds[] = (int)$recipient->id;
        $recipient->refresh();

        return $recipient;
    }

    /**
     * Schedule a recipient that the code under test created for cleanup.
     */
    protected function trackRecipientForCleanup(int $recipientId): void
    {
        $this->recipientIds[] = $recipientId;
    }

    /**
     * Schedule a campaign that the code under test created for cleanup.
     */
    protected function trackCampaignForCleanup(int $campaignId): void
    {
        $this->campaignIds[] = $campaignId;
    }

    /**
     * Save a Formie submission the way Formie's submit request does, then hand
     * it to Formie's after-submission lifecycle with the invitation code in
     * the request query string.
     *
     * @param array<string, mixed> $fieldValues
     */
    protected function submitForm(
        Form $form,
        ?string $invitationCode,
        ?int $siteId = null,
        array $fieldValues = [],
        bool $success = true,
        bool $isIncomplete = false,
        bool $isSpam = false,
    ): Submission {
        $submission = new Submission();
        $submission->setForm($form);
        $submission->siteId = $siteId ?? (int)Craft::$app->getSites()->getPrimarySite()->id;
        $submission->isNewSubmission = true;
        $submission->isIncomplete = $isIncomplete;
        $submission->isSpam = $isSpam;
        $submission->title = $this->nextTestMarker('Campaign Manager test ', 'submission');

        foreach ($fieldValues as $handle => $value) {
            $submission->setFieldValue($handle, $value);
        }

        $this->saveTestElement($submission, false, false, false);

        $this->installInvitationRequest($invitationCode === null ? [] : ['code' => $invitationCode]);
        Formie::$plugin->getSubmissions()->onAfterSubmission($success, $submission);

        return $submission;
    }

    /**
     * Install a request carrying the given query string. The original request
     * is restored during teardown.
     *
     * @param array<string, mixed> $queryParams
     */
    protected function installInvitationRequest(array $queryParams): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
        }

        Craft::$app->set('request', new InvitationRequest($queryParams));
    }

    /**
     * Reload a recipient's persisted state.
     */
    protected function reloadRecipient(RecipientRecord $recipient): RecipientRecord
    {
        $reloaded = RecipientRecord::findOne($recipient->id);
        if ($reloaded === null) {
            throw new RuntimeException("Test recipient {$recipient->id} no longer exists.");
        }

        return $reloaded;
    }

    /**
     * Read a submission's persisted field values by handle, bypassing any
     * in-memory state. Only fields of the submission's form are returned.
     *
     * @return array<string, mixed>
     */
    protected function persistedSubmissionContent(Submission $submission): array
    {
        $persisted = Submission::find()
            ->id($submission->id)
            ->siteId('*')
            ->status(null)
            ->isIncomplete(null)
            ->isSpam(null)
            ->one();

        if (!$persisted instanceof Submission) {
            throw new RuntimeException("Test submission {$submission->id} no longer exists.");
        }

        $content = [];
        foreach ($persisted->getFields() as $field) {
            $content[$field->handle] = $persisted->getFieldValue($field->handle);
        }

        return $content;
    }

    /**
     * Read a recipient's persisted modification time as a Unix timestamp.
     */
    protected function persistedRecipientUpdatedAt(RecipientRecord $recipient): int
    {
        $dateUpdated = (new Query())
            ->select('dateUpdated')
            ->from(RecipientRecord::tableName())
            ->where(['id' => $recipient->id])
            ->scalar();

        $updatedAt = DateTimeHelper::toDateTime($dateUpdated);
        if ($updatedAt === false) {
            throw new RuntimeException("Test recipient {$recipient->id} has no modification time.");
        }

        return $updatedAt->getTimestamp();
    }

    /**
     * Move a recipient's modification time into the past, as if the given
     * time had passed since it was last changed.
     */
    protected function ageRecipient(RecipientRecord $recipient, string $age): void
    {
        $aged = $this->reloadRecipient($recipient);
        $aged->dateUpdated = Db::prepareDateForDb(new DateTime('-' . $age));

        if (!$aged->save(false)) {
            throw new RuntimeException("Test recipient {$recipient->id} failed to save.");
        }
    }

    /**
     * Return a site other than the primary site, skipping the test when the
     * project only has one site.
     */
    protected function secondarySiteId(): int
    {
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if ((int)$siteId !== $primarySiteId) {
                return (int)$siteId;
            }
        }

        self::markTestSkipped('This behavior needs a project with at least two sites.');
    }

    private function trackForm(Form $form): void
    {
        $formId = (int)$form->id;
        $layoutId = (int)(new Query())
            ->select('layoutId')
            ->from('{{%formie_forms}}')
            ->where(['id' => $formId])
            ->scalar();

        if ($layoutId <= 0) {
            throw new RuntimeException("Saved Formie form {$formId} has no persisted layout ID.");
        }

        $this->formFixtures[$formId] = [
            'formId' => $formId,
            'elementIds' => [$formId],
            'submissionIds' => [],
            'layoutIds' => [$layoutId],
            'pageIds' => $this->ids('{{%formie_fieldlayout_pages}}', ['layoutId' => $layoutId]),
            'rowIds' => $this->ids('{{%formie_fieldlayout_rows}}', ['layoutId' => $layoutId]),
            'fieldIds' => $this->ids('{{%formie_fields}}', ['layoutId' => $layoutId]),
        ];
    }

    private function trackSubmission(Submission $submission): void
    {
        $formId = (int)$submission->formId;
        if (!isset($this->formFixtures[$formId])) {
            throw new RuntimeException("Formie form fixture {$formId} is not tracked.");
        }

        $this->formFixtures[$formId]['elementIds'][] = (int)$submission->id;
        $this->formFixtures[$formId]['submissionIds'][] = (int)$submission->id;
    }

    private function restoreRequest(): void
    {
        if ($this->originalRequest === null) {
            return;
        }

        Craft::$app->set('request', $this->originalRequest);
        $this->originalRequest = null;
    }

    private function cleanupRecipients(): void
    {
        if ($this->recipientIds === []) {
            return;
        }

        RecipientRecord::deleteAll(['id' => $this->recipientIds]);

        if ($this->countIds(RecipientRecord::tableName(), $this->recipientIds) !== 0) {
            throw new RuntimeException('Tracked test recipients still exist after cleanup.');
        }

        $this->recipientIds = [];
    }

    private function cleanupCampaigns(): void
    {
        foreach (array_reverse($this->campaignIds) as $campaignId) {
            $this->hardDeleteElement($campaignId);
        }

        if ($this->countIds('{{%elements}}', $this->campaignIds) !== 0
            || $this->countIds('{{%campaignmanager_campaigns}}', $this->campaignIds) !== 0
            || $this->countRows('{{%campaignmanager_campaigns_content}}', ['campaignId' => $this->campaignIds]) !== 0
        ) {
            throw new RuntimeException('Tracked test campaigns still have residue after cleanup.');
        }

        $this->campaignIds = [];
    }

    private function cleanupFormFixtures(): void
    {
        if ($this->formFixtures === []) {
            return;
        }

        $residue = [];

        foreach (array_reverse($this->formFixtures, true) as $formId => $fixture) {
            foreach (array_reverse($fixture['elementIds']) as $elementId) {
                $this->hardDeleteElement($elementId);
            }

            foreach ($fixture['layoutIds'] as $layoutId) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%formie_fieldlayouts}}', ['id' => $layoutId])
                    ->execute();
            }

            if ($this->countIds('{{%elements}}', $fixture['elementIds']) !== 0
                || $this->countIds('{{%formie_forms}}', [$fixture['formId']]) !== 0
                || $this->countIds('{{%formie_submissions}}', $fixture['submissionIds']) !== 0
                || $this->countIds('{{%formie_fieldlayouts}}', $fixture['layoutIds']) !== 0
                || $this->countIds('{{%formie_fieldlayout_pages}}', $fixture['pageIds']) !== 0
                || $this->countIds('{{%formie_fieldlayout_rows}}', $fixture['rowIds']) !== 0
                || $this->countIds('{{%formie_fields}}', $fixture['fieldIds']) !== 0
                || $this->countRows('{{%searchindex}}', ['elementId' => $fixture['elementIds']]) !== 0
            ) {
                $residue[] = $formId;
            }

            unset($this->formFixtures[$formId]);
        }

        // Formie caches every known field handle. Dropping the cache makes it
        // rebuild from the database, without the deleted fixture fields.
        FormieFields::resetFieldHandles();

        if ($residue !== []) {
            throw new RuntimeException('Tracked Formie forms still have residue: ' . implode(', ', $residue));
        }
    }

    private function hardDeleteElement(int $elementId): void
    {
        // Submissions only exist on the site they were posted to
        $element = Craft::$app->getElements()->getElementById($elementId, null, '*', [
            'status' => null,
            'trashed' => null,
        ]);

        if ($element !== null && !Craft::$app->getElements()->deleteElement($element, true)) {
            throw new RuntimeException("Unable to delete tracked test element {$elementId}.");
        }
    }

    /**
     * @param array<string, mixed> $where
     * @return list<int>
     */
    private function ids(string $table, array $where): array
    {
        return array_map('intval', (new Query())
            ->select('id')
            ->from($table)
            ->where($where)
            ->orderBy('id')
            ->column());
    }

    /**
     * @param list<int> $ids
     */
    private function countIds(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int)(new Query())->from($table)->where(['id' => $ids])->count();
    }
}
