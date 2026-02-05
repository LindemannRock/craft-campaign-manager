<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\behaviors;

use lindemannrock\campaignmanager\records\RecipientRecord;
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
    private ?RecipientRecord $_recipient = null;

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [];
    }

    /**
     * Get the recipient
     *
     * @since 5.0.0
     */
    public function getRecipient(): ?RecipientRecord
    {
        return $this->_recipient;
    }

    /**
     * Set the recipient
     *
     * @since 5.0.0
     */
    public function setRecipient(RecipientRecord $recipient): void
    {
        $this->_recipient = $recipient;
    }
}
