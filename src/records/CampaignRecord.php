<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\records;

use craft\base\ElementInterface;
use craft\db\ActiveRecord;
use craft\records\Element;
use lindemannrock\smsmanager\SmsManager;
use verbb\formie\elements\Form;
use verbb\formie\Formie;
use yii\db\ActiveQueryInterface;

/**
 * Campaign Record (non-translatable fields)
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 *
 * @property int $id
 * @property string $campaignType
 * @property int|null $formId
 * @property string|null $invitationDelayPeriod
 * @property string|null $invitationExpiryPeriod
 * @property string|null $providerHandle
 * @property string|null $senderId
 * @property Element $element
 */
class CampaignRecord extends ActiveRecord
{
    public const TABLE_NAME = 'campaignmanager_campaigns';

    private ?Form $_form = null;

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%campaignmanager_campaigns}}';
    }

    /**
     * @inheritdoc
     *
     * Derives `providerHandle` from the chosen `senderId` (which stores a
     * sender handle, not an int — the field is misleadingly named for
     * historical reasons). The campaign edit UI exposes only the Sender
     * ID dropdown with optgroups; the provider is implicit via the
     * chosen sender's `providerHandle`. Keeping the field auto-synced
     * lets recipients pages (`recipients/map.twig`, `recipients/add.twig`)
     * continue using `campaign.providerHandle` for country filtering
     * without change.
     *
     * - `senderId = '<handle>'` → derive provider from that sender
     * - `senderId = ''` (Use SMS Manager default) → null the provider too;
     *   recipients pages fall back to
     *   `SmsService::getResolvedDefaultSenderProvider()` which resolves
     *   SMS Manager's currently-configured default sender's provider.
     * - `senderId = null` → null the provider.
     */
    public function beforeValidate(): bool
    {
        if (!empty($this->senderId)) {
            $sender = SmsManager::$plugin->senderIds->getSenderIdByHandle((string) $this->senderId);
            $this->providerHandle = ($sender && $sender->providerHandle)
                ? (string) $sender->providerHandle
                : null;
        } else {
            $this->providerHandle = null;
        }

        return parent::beforeValidate();
    }

    /**
     * Returns the campaign's element.
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    /**
     * Get the associated Formie form
     */
    public function getForm(): ?Form
    {
        if (!isset($this->_form)) {
            $this->loadForm();
        }

        return $this->_form;
    }

    /**
     * Load the form
     */
    public function loadForm(?Form $form = null): void
    {
        $this->_form = $form;
        if (!$this->_form && $this->formId) {
            $this->_form = Formie::getInstance()->getForms()->getFormById($this->formId);
        }
    }

    /**
     * Reset the form cache
     */
    public function resetForm(): void
    {
        $this->_form = null;
    }

    /**
     * Get all recipients for this campaign
     *
     * @return RecipientRecord[]
     */
    public function getRecipients(): array
    {
        return RecipientRecord::findAll([
            'campaignId' => $this->id,
        ]);
    }

    /**
     * Get recipients by site ID
     *
     * @return RecipientRecord[]
     */
    public function getRecipientsBySiteId(int $siteId): array
    {
        return RecipientRecord::findAll([
            'campaignId' => $this->id,
            'siteId' => $siteId,
        ]);
    }

    /**
     * Get recipients with pending SMS invitations
     *
     * @return array<RecipientRecord>
     */
    public function getPendingSmsRecipients(int $siteId): array
    {
        /** @var array<RecipientRecord> $results */
        $results = RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $siteId,
                'smsSendDate' => null,
            ])
            ->andWhere(['not', ['sms' => null]])
            ->andWhere(['not', ['sms' => '']])
            ->all();

        return $results;
    }

    /**
     * Get recipients with pending email invitations
     *
     * @return array<RecipientRecord>
     */
    public function getPendingEmailRecipients(int $siteId): array
    {
        /** @var array<RecipientRecord> $results */
        $results = RecipientRecord::find()
            ->where([
                'campaignId' => $this->id,
                'siteId' => $siteId,
                'emailSendDate' => null,
            ])
            ->andWhere(['not', ['email' => null]])
            ->andWhere(['not', ['email' => '']])
            ->all();

        return $results;
    }

    /**
     * Get content for a specific site
     */
    public function getContentForSite(int $siteId): ?CampaignContentRecord
    {
        return CampaignContentRecord::findOne([
            'campaignId' => $this->id,
            'siteId' => $siteId,
        ]);
    }

    /**
     * Find a campaign record (main table only)
     */
    public static function findOneForSite(?int $id, ?int $siteId): ?self
    {
        if (!$id) {
            return null;
        }

        /** @var self|null $result */
        $result = static::findOne($id);

        return $result;
    }

    /**
     * Get cloneable attributes
     *
     * @return array<string, mixed>
     */
    public function getCloneableAttributes(): array
    {
        return $this->getAttributes(
            null,
            [
                'id',
                'dateCreated',
                'dateUpdated',
                'uid',
            ],
        );
    }

    /**
     * Create a campaign record for an element
     *
     * @param array<string, mixed> $attributes
     */
    public static function makeForElement(ElementInterface $element, array $attributes = []): self
    {
        return new self([
            'id' => $element->id,
            ...$attributes,
        ]);
    }
}
