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
use lindemannrock\campaignmanager\records\CustomerRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use verbb\formie\elements\Form;
use verbb\formie\Formie;

/**
 * Campaign element
 *
 * @property-read Form|null $form
 * @property-read CustomerRecord[] $customers
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
     * @var string|null Sender ID for SMS (non-translatable)
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
            'campaignType' => ['label' => Craft::t('campaign-manager', 'Type')],
            'form' => ['label' => Craft::t('campaign-manager', 'Form')],
            'customerCount' => ['label' => Craft::t('campaign-manager', 'Customers')],
            'actions' => ['label' => Craft::t('campaign-manager', 'Actions')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'campaignType',
            'form',
            'customerCount',
            'actions',
            'dateCreated',
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
        $this->setLoggingHandle('campaign-manager');

        // Load content for current site if we have an ID
        if ($this->id && $this->siteId && $this->emailInvitationMessage === null) {
            $this->loadContent();
        }
    }

    /**
     * Load translatable content for the current site
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
     * Get the associated Formie form
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
     */
    public function setForm(?Form $form): void
    {
        $this->_form = $form;
        $this->formId = $form?->id;
    }

    /**
     * Get all customers for this campaign
     *
     * @return CustomerRecord[]
     */
    public function getCustomers(): array
    {
        if (!$this->id) {
            return [];
        }

        return CustomerRecord::findAll([
            'campaignId' => $this->id,
            'siteId' => $this->siteId,
        ]);
    }

    /**
     * Get customer count
     */
    public function getCustomerCount(): int
    {
        if (!$this->id) {
            return 0;
        }

        return CustomerRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
            ])
            ->count();
    }

    /**
     * Get actions attribute (used for table attribute)
     * The actual HTML is rendered via getTableAttributeHtml()
     */
    public function getActions(): ?string
    {
        return null;
    }

    /**
     * Get customers with pending SMS invitations
     *
     * @return CustomerRecord[]
     */
    public function getPendingSmsCustomers(): array
    {
        if (!$this->id) {
            return [];
        }

        /** @var CustomerRecord[] $customers */
        $customers = CustomerRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
                'smsSendDate' => null,
            ])
            ->andWhere(['not', ['sms' => null]])
            ->andWhere(['not', ['sms' => '']])
            ->all();

        return $customers;
    }

    /**
     * Get customers with pending email invitations
     *
     * @return CustomerRecord[]
     */
    public function getPendingEmailCustomers(): array
    {
        if (!$this->id) {
            return [];
        }

        /** @var CustomerRecord[] $customers */
        $customers = CustomerRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $this->siteId,
                'emailSendDate' => null,
            ])
            ->andWhere(['not', ['email' => null]])
            ->andWhere(['not', ['email' => '']])
            ->all();

        return $customers;
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
        $rules[] = [['emailInvitationSubject', 'senderId'], 'string', 'max' => 255];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getTableAttributeHtml(string $attribute): string
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

            case 'customerCount':
                $count = $this->getCustomerCount();
                if ($count > 0) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        UrlHelper::cpUrl("campaign-manager/campaigns/{$this->id}/customers"),
                        number_format($count)
                    );
                }
                return '0';

            case 'campaignType':
                return $this->campaignType ?: '—';

            case 'actions':
                return $this->getActionsHtml();
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * Get the HTML for the actions dropdown menu
     */
    protected function getActionsHtml(): string
    {
        $customersUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$this->id}/customers");
        $addCustomerUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$this->id}/add-customer");
        $importCustomersUrl = UrlHelper::cpUrl("campaign-manager/campaigns/{$this->id}/import-customers");

        $viewCustomers = Craft::t('campaign-manager', 'View Customers');
        $addCustomer = Craft::t('campaign-manager', 'Add Customer');
        $importCustomers = Craft::t('campaign-manager', 'Import Customers');
        $runCampaign = Craft::t('campaign-manager', 'Run Campaign');
        $actionsLabel = Craft::t('campaign-manager', 'Actions');

        return <<<HTML
<div class="campaign-actions-menu" style="text-align: right;">
    <button type="button" class="btn menubtn" data-icon="settings" title="{$actionsLabel}"></button>
    <div class="menu">
        <ul>
            <li><a href="{$customersUrl}">{$viewCustomers}</a></li>
            <li><a href="{$addCustomerUrl}">{$addCustomer}</a></li>
            <li><a href="{$importCustomersUrl}">{$importCustomers}</a></li>
            <li><hr class="padded"></li>
            <li><a class="campaign-run-action" data-campaign-id="{$this->id}">{$runCampaign}</a></li>
        </ul>
    </div>
</div>
HTML;
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

        // Delete all customers for this campaign
        CustomerRecord::deleteAll([
            'campaignId' => $this->id,
        ]);

        return true;
    }
}
