<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\behaviors;

use lindemannrock\campaignmanager\records\CustomerRecord;
use verbb\formie\elements\Form;
use yii\base\Behavior;

/**
 * Form Behavior
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 *
 * @property Form $owner
 */
class FormBehavior extends Behavior
{
    private ?CustomerRecord $_customer = null;

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [];
    }

    /**
     * Get the customer
     */
    public function getCustomer(): ?CustomerRecord
    {
        return $this->_customer;
    }

    /**
     * Set the customer
     */
    public function setCustomer(CustomerRecord $customer): void
    {
        $this->_customer = $customer;
    }
}
