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
use lindemannrock\campaignmanager\records\CustomerRecord;
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
     * @var CustomerRecord[]|null
     */
    private ?array $_customers = null;

    private ?int $_customerCount = null;

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
        \Craft::info('saveCampaignRecord - called with attributes: ' . json_encode($attributes), 'campaign-manager');

        // Use canonical element ID to avoid saving to drafts/revisions
        $canonicalId = $this->owner->getCanonicalId() ?? $this->owner->id;
        $siteId = $this->owner->siteId;

        // No record loaded and no attributes - check if this is a campaign element
        if (!isset($this->_record) && empty($attributes)) {
            // Only create records for elements in the campaigns section
            if (!$this->isCampaignElement()) {
                \Craft::info('saveCampaignRecord - skipping: not a campaign element and no attributes', 'campaign-manager');
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
            \Craft::info('saveCampaignRecord - skipping: record not modified', 'campaign-manager');
            return;
        }

        // Save main record
        $record->setAttributes($mainAttributes, false);

        \Craft::info('saveCampaignRecord - saving record id=' . $record->id . ', isNew=' . ($record->getIsNewRecord() ? 'yes' : 'no'), 'campaign-manager');
        if (!$record->save()) {
            \Craft::error('saveCampaignRecord - save failed: ' . json_encode($record->getErrors()), 'campaign-manager');
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
                \Craft::error('saveCampaignRecord - content save failed: ' . json_encode($contentRecord->getErrors()), 'campaign-manager');
                throw new Exception('Could not save the Campaign content record.');
            }
            $this->_contentRecord = $contentRecord;
        }

        \Craft::info('saveCampaignRecord - saved successfully', 'campaign-manager');

        $this->loadCampaignRecord($record);
    }

    /**
     * Set Campaign record attributes
     *
     * @param array<string, mixed> $attributes
     */
    public function setCampaignRecordAttributes(array $attributes): void
    {
        \Craft::info('setCampaignRecordAttributes called with: ' . json_encode($attributes), 'campaign-manager');
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

        \Craft::info('handleAfterSave - pendingAttributes: ' . json_encode($this->_pendingAttributes), 'campaign-manager');
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
     * @return CustomerRecord[]
     */
    public function getCustomers(): array
    {
        if (!isset($this->_customers)) {
            $this->_customers = $this->getCampaignManagerRecord()?->getCustomers() ?? [];
        }

        return $this->_customers;
    }

    /**
     * @return CustomerRecord[]
     */
    public function getCustomersBySiteId(int $siteId): array
    {
        if (!isset($this->_customers)) {
            $this->_customers = $this->getCampaignManagerRecord()?->getCustomersBySiteId($siteId) ?? [];
        }

        return $this->_customers;
    }

    public function getCustomerCount(): int
    {
        if (!isset($this->_customerCount)) {
            $this->_customerCount = count($this->getCustomers());
        }

        return $this->_customerCount;
    }

    public function getCustomerCountBySiteId(int $siteId): int
    {
        if (!isset($this->_customerCount)) {
            $this->_customerCount = count($this->getCustomersBySiteId($siteId));
        }

        return $this->_customerCount;
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
            $customersWithSubmissions = array_filter(
                $this->getCustomers(),
                fn(CustomerRecord $customer) => (bool)$customer->submissionId
            );
            $this->_submissionCount = count($customersWithSubmissions);
        }

        return $this->_submissionCount;
    }

    public function getSentCount(): int
    {
        if (!isset($this->_sentCount)) {
            $customersWithSent = array_filter(
                $this->getCustomers(),
                fn(CustomerRecord $customer) => $customer->smsSendDate !== null || $customer->emailSendDate !== null
            );
            $this->_sentCount = count($customersWithSent);
        }

        return $this->_sentCount;
    }

    public function getSentCountBySiteId(int $siteId): int
    {
        $customersWithSent = array_filter(
            $this->getCustomersBySiteId($siteId),
            fn(CustomerRecord $customer) => $customer->smsSendDate !== null || $customer->emailSendDate !== null
        );

        return count($customersWithSent);
    }

    public function getSmsOpenedCount(): int
    {
        if (!isset($this->_smsOpenedCount)) {
            $customersWithSmsOpened = array_filter(
                $this->getCustomers(),
                fn(CustomerRecord $customer) => $customer->smsOpenDate !== null
            );
            $this->_smsOpenedCount = count($customersWithSmsOpened);
        }

        return $this->_smsOpenedCount;
    }

    public function getSmsOpenedCountBySiteId(int $siteId): int
    {
        $customersWithSmsOpened = array_filter(
            $this->getCustomersBySiteId($siteId),
            fn(CustomerRecord $customer) => $customer->smsOpenDate !== null
        );

        return count($customersWithSmsOpened);
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
