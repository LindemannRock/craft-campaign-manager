<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\behaviors;

use craft\base\Element;
use craft\events\ModelEvent;
use Exception;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\CampaignContentRecord;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use verbb\formie\elements\Form;
use yii\base\Behavior;

/**
 * Campaign Behavior
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 *
 * @property string $campaignType
 * @property int|null $formId
 * @property string|null $senderId
 * @property string|null $emailInvitationSubject
 * @property string|null $invitationDelayPeriod
 * @property string|null $invitationExpiryPeriod
 * @property string|null $emailInvitationMessage
 * @property string|null $smsInvitationMessage
 * @property Element $owner
 */
class CampaignBehavior extends Behavior
{
    /**
     * @var CampaignRecord|null Internal copy of the Campaign record
     */
    private ?CampaignRecord $_record = null;

    /**
     * @var CampaignContentRecord|null Internal copy of the Campaign content record
     */
    private ?CampaignContentRecord $_contentRecord = null;

    /**
     * @var bool Whether we've already tried to load records
     */
    private bool $_recordLoaded = false;

    /**
     * @var RecipientRecord[]|null
     */
    private ?array $_recipients = null;

    private ?int $_recipientCount = null;

    private ?int $_submissionCount = null;

    private ?int $_sentCount = null;

    private ?int $_smsOpenedCount = null;

    /**
     * @var array<string, mixed>|null Pending attributes to save
     */
    private ?array $_pendingAttributes = null;

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [
            ...parent::events(),
            Element::EVENT_AFTER_SAVE => fn(ModelEvent $event) => $this->handleAfterSave($event),
        ];
    }

    /**
     * Get the Campaign record
     */
    public function getCampaignManagerRecord(): ?CampaignRecord
    {
        // Skip Campaign elements - they have their own properties
        if ($this->owner instanceof Campaign) {
            return null;
        }

        if (!$this->_recordLoaded) {
            $this->loadCampaignRecord();
        }

        return $this->_record;
    }

    /**
     * Get the Campaign content record for current site
     */
    public function getCampaignContentRecord(): ?CampaignContentRecord
    {
        // Skip Campaign elements - they have their own properties
        if ($this->owner instanceof Campaign) {
            return null;
        }

        if (!$this->_recordLoaded) {
            $this->loadCampaignRecord();
        }

        return $this->_contentRecord;
    }

    /**
     * Load the Campaign record
     */
    public function loadCampaignRecord(?CampaignRecord $record = null): void
    {
        if ($record) {
            $this->_record = $record;
        } else {
            $this->_record = CampaignRecord::findOneForSite($this->owner->id, $this->owner->siteId);
        }

        // Also load the content record for this site
        if ($this->_record) {
            $this->_contentRecord = $this->_record->getContentForSite($this->owner->siteId);
        }

        $this->_recordLoaded = true;
    }

    /**
     * Save the Campaign record
     *
     * @param array<string, mixed>|null $attributes
     * @throws Exception
     */
    public function saveCampaignRecord(?array $attributes = null): void
    {
        // Use canonical element ID to avoid saving to drafts/revisions
        $canonicalId = $this->owner->getCanonicalId() ?? $this->owner->id;
        $siteId = $this->owner->siteId;

        // No record loaded and no attributes - check if this is a campaign element
        if (!isset($this->_record) && empty($attributes)) {
            // Only create records for elements in the campaigns section
            if (!$this->isCampaignElement()) {
                return;
            }
        }

        // Always look up the record for the canonical ID
        $record = CampaignRecord::findOne($canonicalId);
        $pendingAttributes = $attributes;

        // If no record exists for canonical ID, get pending attributes from current record if any
        if ($record === null) {
            $currentRecord = $this->getCampaignManagerRecord();
            if ($currentRecord !== null) {
                $pendingAttributes = array_merge($currentRecord->getCloneableAttributes(), $attributes ?? []);
            }
            $record = new CampaignRecord();
            $record->id = $canonicalId;
        }

        // Separate translatable attributes
        $translatableKeys = ['emailInvitationMessage', 'emailInvitationSubject', 'smsInvitationMessage'];
        $mainAttributes = [];
        $contentAttributes = [];

        foreach ($pendingAttributes ?? [] as $key => $value) {
            if (in_array($key, $translatableKeys, true)) {
                $contentAttributes[$key] = $value;
            } else {
                $mainAttributes[$key] = $value;
            }
        }

        // Record not modified and not new; nothing to save.
        if (!$record->getIsNewRecord() && empty($record->getDirtyAttributes()) && empty($mainAttributes) && empty($contentAttributes)) {
            return;
        }

        // Save main record
        $record->setAttributes($mainAttributes, false);

        if (!$record->save()) {
            throw new Exception('Could not save the Campaign record.');
        }

        // Save content record for this site
        if (!empty($contentAttributes)) {
            $contentRecord = CampaignContentRecord::findOne([
                'campaignId' => $canonicalId,
                'siteId' => $siteId,
            ]);

            if (!$contentRecord) {
                $contentRecord = new CampaignContentRecord();
                $contentRecord->campaignId = $canonicalId;
                $contentRecord->siteId = $siteId;
            }

            $contentRecord->setAttributes($contentAttributes, false);
            if (!$contentRecord->save()) {
                throw new Exception('Could not save the Campaign content record.');
            }
            $this->_contentRecord = $contentRecord;
        }

        $this->loadCampaignRecord($record);
    }

    /**
     * Set Campaign record attributes
     *
     * @param array<string, mixed> $attributes
     */
    public function setCampaignRecordAttributes(array $attributes): void
    {
        $this->_pendingAttributes = $attributes;

        // Separate translatable attributes
        $translatableKeys = ['emailInvitationMessage', 'emailInvitationSubject', 'smsInvitationMessage'];
        $mainAttributes = [];

        foreach ($attributes as $key => $value) {
            if (!in_array($key, $translatableKeys, true)) {
                $mainAttributes[$key] = $value;
            }
        }

        $this->getOrMakeRecord()->setAttributes($mainAttributes, false);
    }

    /**
     * Handle after save event
     */
    protected function handleAfterSave(ModelEvent $event): void
    {
        $owner = $event->sender;

        // Skip Campaign elements - they handle their own afterSave
        if ($owner instanceof Campaign) {
            return;
        }

        $owner->saveCampaignRecord($this->_pendingAttributes);
        $this->_pendingAttributes = null;
    }

    // Getters and setters

    public function getCampaignType(): ?string
    {
        return $this->getCampaignManagerRecord()?->campaignType;
    }

    public function setCampaignType(?string $value): void
    {
        $this->getOrMakeRecord()->campaignType = $value ?: null;
    }

    /**
     * @return RecipientRecord[]
     */
    public function getRecipients(): array
    {
        if (!isset($this->_recipients)) {
            $this->_recipients = $this->getCampaignManagerRecord()?->getRecipients() ?? [];
        }

        return $this->_recipients;
    }

    /**
     * @return RecipientRecord[]
     */
    public function getRecipientsBySiteId(int $siteId): array
    {
        if (!isset($this->_recipients)) {
            $this->_recipients = $this->getCampaignManagerRecord()?->getRecipientsBySiteId($siteId) ?? [];
        }

        return $this->_recipients;
    }

    public function getRecipientCount(): int
    {
        if (!isset($this->_recipientCount)) {
            $this->_recipientCount = count($this->getRecipients());
        }

        return $this->_recipientCount;
    }

    public function getRecipientCountBySiteId(int $siteId): int
    {
        if (!isset($this->_recipientCount)) {
            $this->_recipientCount = count($this->getRecipientsBySiteId($siteId));
        }

        return $this->_recipientCount;
    }

    public function getFormId(): ?int
    {
        return $this->getCampaignManagerRecord()?->formId;
    }

    public function setFormId(?int $value): void
    {
        $this->getOrMakeRecord()->formId = $value ?: null;
        $this->getOrMakeRecord()->resetForm();
    }

    public function getInvitationDelayPeriod(): ?string
    {
        return $this->getCampaignManagerRecord()?->invitationDelayPeriod;
    }

    public function setInvitationDelayPeriod(?string $value): void
    {
        $this->getOrMakeRecord()->invitationDelayPeriod = $value ?: null;
    }

    public function getInvitationExpiryPeriod(): ?string
    {
        return $this->getCampaignManagerRecord()?->invitationExpiryPeriod;
    }

    public function setInvitationExpiryPeriod(?string $value): void
    {
        $this->getOrMakeRecord()->invitationExpiryPeriod = $value ?: null;
    }

    public function getSenderId(): ?string
    {
        return $this->getCampaignManagerRecord()?->senderId;
    }

    public function getEmailInvitationSubject(): ?string
    {
        return $this->getCampaignContentRecord()?->emailInvitationSubject;
    }

    public function getEmailInvitationMessage(): ?string
    {
        return $this->getCampaignContentRecord()?->emailInvitationMessage;
    }

    public function setEmailInvitationMessage(?string $value): void
    {
        $this->getOrMakeContentRecord()->emailInvitationMessage = $value ?: null;
    }

    public function getSmsInvitationMessage(): ?string
    {
        return $this->getCampaignContentRecord()?->smsInvitationMessage;
    }

    public function setSmsInvitationMessage(?string $value): void
    {
        $this->getOrMakeContentRecord()->smsInvitationMessage = $value ?: null;
    }

    public function getSubmissionCount(): int
    {
        if (!isset($this->_submissionCount)) {
            $recipientsWithSubmissions = array_filter(
                $this->getRecipients(),
                fn(RecipientRecord $recipient) => (bool)$recipient->submissionId
            );
            $this->_submissionCount = count($recipientsWithSubmissions);
        }

        return $this->_submissionCount;
    }

    public function getSentCount(): int
    {
        if (!isset($this->_sentCount)) {
            $recipientsWithSent = array_filter(
                $this->getRecipients(),
                fn(RecipientRecord $recipient) => $recipient->smsSendDate !== null || $recipient->emailSendDate !== null
            );
            $this->_sentCount = count($recipientsWithSent);
        }

        return $this->_sentCount;
    }

    public function getSentCountBySiteId(int $siteId): int
    {
        $recipientsWithSent = array_filter(
            $this->getRecipientsBySiteId($siteId),
            fn(RecipientRecord $recipient) => $recipient->smsSendDate !== null || $recipient->emailSendDate !== null
        );

        return count($recipientsWithSent);
    }

    public function getSmsOpenedCount(): int
    {
        if (!isset($this->_smsOpenedCount)) {
            $recipientsWithSmsOpened = array_filter(
                $this->getRecipients(),
                fn(RecipientRecord $recipient) => $recipient->smsOpenDate !== null
            );
            $this->_smsOpenedCount = count($recipientsWithSmsOpened);
        }

        return $this->_smsOpenedCount;
    }

    public function getSmsOpenedCountBySiteId(int $siteId): int
    {
        $recipientsWithSmsOpened = array_filter(
            $this->getRecipientsBySiteId($siteId),
            fn(RecipientRecord $recipient) => $recipient->smsOpenDate !== null
        );

        return count($recipientsWithSmsOpened);
    }

    public function getForm(): ?Form
    {
        return $this->getCampaignManagerRecord()?->getForm();
    }

    /**
     * Get or create a Campaign record
     */
    private function getOrMakeRecord(): CampaignRecord
    {
        $record = $this->getCampaignManagerRecord();
        if (!$record) {
            $this->_record = CampaignRecord::makeForElement($this->owner);
        }

        return $this->_record;
    }

    /**
     * Get or create a Campaign content record
     */
    private function getOrMakeContentRecord(): CampaignContentRecord
    {
        $record = $this->getCampaignContentRecord();
        if (!$record) {
            $this->_contentRecord = new CampaignContentRecord();
            $this->_contentRecord->campaignId = $this->owner->id;
            $this->_contentRecord->siteId = $this->owner->siteId;
        }

        return $this->_contentRecord;
    }

    /**
     * Check if the owner element is a campaign element (in the configured section)
     */
    private function isCampaignElement(): bool
    {
        $owner = $this->owner;

        // Must be an Entry
        if (!$owner instanceof \craft\elements\Entry) {
            return false;
        }

        // Check if in the configured campaigns section
        $settings = \lindemannrock\campaignmanager\CampaignManager::$plugin->getSettings();
        $sectionHandle = $settings->campaignSectionHandle;

        if (empty($sectionHandle)) {
            return false;
        }

        $section = $owner->getSection();
        return $section && $section->handle === $sectionHandle;
    }
}
