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
     * Get all customers for this campaign
     *
     * @return CustomerRecord[]
     */
    public function getCustomers(): array
    {
        return CustomerRecord::findAll([
            'campaignId' => $this->id,
        ]);
    }

    /**
     * Get customers by site ID
     *
     * @return CustomerRecord[]
     */
    public function getCustomersBySiteId(int $siteId): array
    {
        return CustomerRecord::findAll([
            'campaignId' => $this->id,
            'siteId' => $siteId,
        ]);
    }

    /**
     * Get customers with pending SMS invitations
     *
     * @return array<CustomerRecord>
     */
    public function getPendingSmsCustomers(int $siteId): array
    {
        /** @var array<CustomerRecord> $results */
        $results = CustomerRecord::find()
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
     * Get customers with pending email invitations
     *
     * @return array<CustomerRecord>
     */
    public function getPendingEmailCustomers(int $siteId): array
    {
        /** @var array<CustomerRecord> $results */
        $results = CustomerRecord::find()
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
