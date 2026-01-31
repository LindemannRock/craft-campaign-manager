<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\actions\SetStatus;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\db\CampaignQuery;
use lindemannrock\campaignmanager\records\CampaignContentRecord;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Form;
use verbb\formie\Formie;

/**
 * Campaign element
 *
 * @property-read Form|null $form
 * @property-read RecipientRecord[] $recipients
 * @since 5.0.0
 */
class Campaign extends Element
{
    use LoggingTrait;

    // Properties
    // =========================================================================

    /**
     * @var string|null Campaign type (non-translatable)
     */
    public ?string $campaignType = null;

    /**
     * @var int|null Formie form ID (non-translatable)
     */
    public ?int $formId = null;

    /**
     * @var string|null Invitation delay period (non-translatable)
     */
    public ?string $invitationDelayPeriod = null;

    /**
     * @var string|null Invitation expiry period (non-translatable)
     */
    public ?string $invitationExpiryPeriod = null;

    /**
     * @var string|null Provider handle for SMS (non-translatable)
     */
    public ?string $providerHandle = null;

    /**
     * @var string|null Sender ID handle for SMS (non-translatable)
     */
    public ?string $senderId = null;

    /**
     * @var string|null Email invitation message (translatable)
     */
    public ?string $emailInvitationMessage = null;

    /**
     * @var string|null Email invitation subject (translatable)
     */
    public ?string $emailInvitationSubject = null;

    /**
     * @var string|null SMS invitation message (translatable)
     */
    public ?string $smsInvitationMessage = null;

    /**
     * @var Form|null Cached form object
     */
    private ?Form $_form = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign-manager', 'Campaign');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('campaign-manager', 'campaign');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('campaign-manager', 'Campaigns');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('campaign-manager', 'campaigns');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'campaign';
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => Craft::t('app', 'Enabled'),
            self::STATUS_DISABLED => Craft::t('app', 'Disabled'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): ?string
    {
        // Check if enabled for the current site
        // This checks the elements_sites.enabled column
        if ($this->enabled === false) {
            return self::STATUS_DISABLED;
        }

        return self::STATUS_ENABLED;
    }

    /**
     * @inheritdoc
     */
    public static function hasDrafts(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function hasRevisions(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     * @return CampaignQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new CampaignQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('campaign-manager', 'All Campaigns'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        // Add campaign type sources if configured
        $settings = CampaignManager::$plugin->getSettings();
        $campaignTypes = $settings->getCampaignTypeOptions();

        if ($campaignTypes) {
            $sources[] = ['heading' => Craft::t('campaign-manager', 'Campaign Types')];

            foreach ($campaignTypes as $value => $label) {
                $sources[] = [
                    'key' => 'type:' . $value,
                    'label' => $label,
                    'criteria' => ['campaignType' => $value],
                ];
            }
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source): array
    {
        $actions = [];

        // View Recipients (single selection)
        $actions[] = actions\ViewRecipientsAction::class;

        // Add Recipient (single selection)
        $actions[] = actions\AddRecipientAction::class;

        // Import Recipients (single selection)
        $actions[] = actions\ImportRecipientsAction::class;

        // Set Status
        $actions[] = SetStatus::class;

        // Delete
        $actions[] = Craft::$app->elements->createAction([
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('campaign-manager', 'Are you sure you want to delete the selected campaigns?'),
            'successMessage' => Craft::t('campaign-manager', 'Campaigns deleted.'),
        ]);

        // Restore
        $actions[] = Craft::$app->elements->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('campaign-manager', 'Campaigns restored.'),
            'partialSuccessMessage' => Craft::t('campaign-manager', 'Some campaigns restored.'),
            'failMessage' => Craft::t('campaign-manager', 'Campaigns not restored.'),
        ]);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function includeSetStatusAction(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            [
                'label' => Craft::t('campaign-manager', 'Campaign Type'),
                'orderBy' => 'campaignmanager_campaigns.campaignType',
                'attribute' => 'campaignType',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'elements.dateUpdated',
                'attribute' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'status' => ['label' => Craft::t('app', 'Status')],
            'campaignType' => ['label' => Craft::t('campaign-manager', 'Type')],
            'form' => ['label' => Craft::t('campaign-manager', 'Form')],
            'recipientCount' => ['label' => Craft::t('campaign-manager', 'Recipients')],
            'submissionCount' => ['label' => Craft::t('campaign-manager', 'Submissions')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
            'actions' => ['label' => ''],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'status',
            'campaignType',
            'form',
            'recipientCount',
            'submissionCount',
            'dateCreated',
            'actions',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'campaignType'];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);

        // Load content for current site if we have an ID
        if ($this->id && $this->siteId && $this->emailInvitationMessage === null) {
            $this->loadContent();
        }
    }

    /**
     * Load translatable content for the current site
     *
     * @since 5.0.0
     */
    public function loadContent(): void
    {
        if (!$this->id || !$this->siteId) {
            return;
        }

        $contentRecord = CampaignContentRecord::findOne([
            'campaignId' => $this->id,
            'siteId' => $this->siteId,
        ]);

        if ($contentRecord) {
            $this->emailInvitationMessage = $contentRecord->emailInvitationMessage;
            $this->emailInvitationSubject = $contentRecord->emailInvitationSubject;
            $this->smsInvitationMessage = $contentRecord->smsInvitationMessage;
        }
    }

    /**
     * @inheritdoc
     */
    public function afterPopulate(): void
    {
        // Load content data for current site
        $this->loadContent();
    }

    /**
     * Get actions (used for table attribute rendering)
     *
     * @since 5.1.0
     */
    public function getActions(): string
    {
        return '';
    }

    /**
     * Get the associated Formie form
     *
     * @since 5.0.0
     */
    public function getForm(): ?Form
    {
        if ($this->_form === null && $this->formId) {
            $this->_form = Formie::getInstance()?->getForms()->getFormById($this->formId);
        }

        return $this->_form;
    }

    /**
     * Set the form
     *
     * @since 5.0.0
     */
    public function setForm(?Form $form): void
    {
        $this->_form = $form;
        $this->formId = $form?->id;
    }

    /**
     * Get all recipients for this campaign
     *
     * @return RecipientRecord[]
     * @since 5.0.0
     */
    public function getRecipients(): array
    {
        if (!$this->id) {
            return [];
        }

        return RecipientRecord::findAll([
            'campaignId' => $this->id,
            'siteId' => $this->siteId,
        ]);
    }

    /**
     * Get recipient count
     *
     * @since 5.0.0
     */
    public function getRecipientCount(): int
    {
        if (!$this->id) {
            return 0;
        }

        return RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
            ])
            ->count();
    }

    /**
     * Get submission count (recipients who have submitted the form)
     *
     * @since 5.0.0
     */
    public function getSubmissionCount(): int
    {
        if (!$this->id) {
            return 0;
        }

        return RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
            ])
            ->andWhere(['not', ['submissionId' => null]])
            ->count();
    }

    /**
     * Check if this campaign has any submissions across ALL sites
     *
     * Use this when checking if the form can be changed - the form is shared
     * across all sites, so we need to check all sites, not just the current one.
     *
     * @since 5.1.0
     */
    public function hasSubmissionsAcrossAllSites(): bool
    {
        if (!$this->id) {
            return false;
        }

        return RecipientRecord::find()
            ->where(['campaignId' => $this->id])
            ->andWhere(['not', ['submissionId' => null]])
            ->exists();
    }

    /**
     * Get recipients with pending SMS invitations
     *
     * @return RecipientRecord[]
     * @since 5.0.0
     */
    public function getPendingSmsRecipients(): array
    {
        if (!$this->id) {
            return [];
        }

        /** @var RecipientRecord[] $recipients */
        $recipients = RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
                'smsSendDate' => null,
            ])
            ->andWhere(['not', ['sms' => null]])
            ->andWhere(['not', ['sms' => '']])
            ->all();

        return $recipients;
    }

    /**
     * Get recipients with pending email invitations
     *
     * @return RecipientRecord[]
     * @since 5.0.0
     */
    public function getPendingEmailRecipients(): array
    {
        if (!$this->id) {
            return [];
        }

        /** @var RecipientRecord[] $recipients */
        $recipients = RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
                'emailSendDate' => null,
            ])
            ->andWhere(['not', ['email' => null]])
            ->andWhere(['not', ['email' => '']])
            ->all();

        return $recipients;
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        // Get field layouts from project config
        $fieldLayouts = Craft::$app->getProjectConfig()->get('campaign-manager.fieldLayouts') ?? [];

        if (!empty($fieldLayouts)) {
            $fieldLayoutUid = array_key_first($fieldLayouts);
            $fieldLayout = Craft::$app->getFields()->getLayoutByUid($fieldLayoutUid);
            if ($fieldLayout) {
                return $fieldLayout;
            }
        }

        // Fallback to getting by type
        return Craft::$app->fields->getLayoutByType(self::class);
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        return $user->can('campaignManager:viewCampaigns');
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        if (!$this->id) {
            return $user->can('campaignManager:createCampaigns');
        }

        return $user->can('campaignManager:editCampaigns');
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        return $user->can('campaignManager:deleteCampaigns');
    }

    /**
     * @inheritdoc
     */
    public function canCreateDrafts(User $user): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function cpEditUrl(): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId);
        $siteHandle = $site?->handle ?? Craft::$app->getSites()->getCurrentSite()->handle;

        return sprintf('campaign-manager/campaigns/%s?site=%s', $this->getCanonicalId(), $siteHandle);
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('campaign-manager');
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): ?string
    {
        return $this->cpEditUrl();
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title', 'formId'], 'required'];
        $rules[] = [['campaignType'], 'string', 'max' => 255];
        $rules[] = [['formId'], 'integer'];
        $rules[] = [['invitationDelayPeriod', 'invitationExpiryPeriod'], 'string', 'max' => 50];
        $rules[] = [['emailInvitationSubject', 'providerHandle', 'senderId'], 'string', 'max' => 255];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'form':
                $form = $this->getForm();
                if ($form) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        $form->getCpEditUrl(),
                        $form->title
                    );
                }
                return '—';

            case 'recipientCount':
                $count = $this->getRecipientCount();
                if ($count > 0) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        UrlHelper::cpUrl("campaign-manager/campaigns/{$this->id}/recipients"),
                        number_format($count)
                    );
                }
                return '0';

            case 'submissionCount':
                $count = $this->getSubmissionCount();
                return number_format($count);

            case 'campaignType':
                return $this->campaignType ?: '—';

            case 'actions':
                $site = Craft::$app->getSites()->getSiteById($this->siteId);
                $siteHandle = $site?->handle ?? 'en';
                $campaignId = $this->getCanonicalId();

                $viewUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$campaignId}/recipients", ['site' => $siteHandle]);
                $addUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$campaignId}/add-recipient", ['site' => $siteHandle]);
                $importUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$campaignId}/import-recipients", ['site' => $siteHandle]);

                return sprintf(
                    '<div class="campaign-actions-menu">
                        <button type="button" class="btn menubtn" data-icon="settings" aria-label="%s"></button>
                        <div class="menu">
                            <ul>
                                <li><a href="%s">%s</a></li>
                                <li><a href="%s">%s</a></li>
                                <li><a href="%s">%s</a></li>
                            </ul>
                        </div>
                    </div>',
                    Craft::t('app', 'Actions'),
                    $viewUrl,
                    Craft::t('campaign-manager', 'View Recipients'),
                    $addUrl,
                    Craft::t('campaign-manager', 'Add Recipient'),
                    $importUrl,
                    Craft::t('campaign-manager', 'Import Recipients')
                );
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        // CRITICAL: Always set elements.enabled = true for Campaigns
        // We use per-site enabling (elements_sites.enabled), not global enabling
        // Craft's default behavior sets elements.enabled=false when ANY site is disabled,
        // which breaks queries that check "elements.enabled AND elements_sites.enabled"
        $this->enabled = true;

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        // Save to main table (non-translatable fields)
        if (!$isNew) {
            $record = CampaignRecord::findOne($this->id);

            if (!$record) {
                throw new \Exception('Invalid campaign ID: ' . $this->id);
            }
        } else {
            $record = new CampaignRecord();
            $record->id = $this->id;
        }

        $record->campaignType = $this->campaignType;
        $record->formId = $this->formId;
        $record->invitationDelayPeriod = $this->invitationDelayPeriod;
        $record->invitationExpiryPeriod = $this->invitationExpiryPeriod;
        $record->providerHandle = $this->providerHandle;
        $record->senderId = $this->senderId;

        $record->save(false);

        // Save to content table (translatable fields)
        $contentRecord = CampaignContentRecord::findOne([
            'campaignId' => $this->id,
            'siteId' => $this->siteId,
        ]);

        if (!$contentRecord) {
            $contentRecord = new CampaignContentRecord();
            $contentRecord->campaignId = $this->id;
            $contentRecord->siteId = $this->siteId;
        }

        $contentRecord->emailInvitationMessage = $this->emailInvitationMessage;
        $contentRecord->emailInvitationSubject = $this->emailInvitationSubject;
        $contentRecord->smsInvitationMessage = $this->smsInvitationMessage;

        $contentRecord->save(false);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        // Delete all recipients for this campaign
        RecipientRecord::deleteAll([
            'campaignId' => $this->id,
        ]);

        return true;
    }
}
