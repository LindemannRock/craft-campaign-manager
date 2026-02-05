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
     * Returns the campaign's element.
     *
     * @since 5.0.0
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    /**
     * Get the associated Formie form
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
     */
    public function resetForm(): void
    {
        $this->_form = null;
    }

    /**
     * Get all recipients for this campaign
     *
     * @return RecipientRecord[]
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public static function makeForElement(ElementInterface $element, array $attributes = []): self
    {
        return new self([
            'id' => $element->id,
            ...$attributes,
        ]);
    }
}
