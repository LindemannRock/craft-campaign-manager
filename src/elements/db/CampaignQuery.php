<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use lindemannrock\campaignmanager\elements\Campaign;

/**
 * CampaignQuery represents a SELECT SQL statement for campaigns.
 *
 * @method Campaign[]|array all($db = null)
 * @method Campaign|array|null one($db = null)
 * @method Campaign|array|null nth(int $n, ?Connection $db = null)
 * @since 5.0.0
 */
class CampaignQuery extends ElementQuery
{
    /**
     * @var mixed|null Campaign type filter
     */
    public mixed $campaignType = null;

    /**
     * @var mixed|null Form ID filter
     */
    public mixed $formId = null;

    /**
     * Narrows the query results based on the campaigns' type.
     */
    public function campaignType(mixed $value): static
    {
        $this->campaignType = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the campaigns' form ID.
     */
    public function formId(mixed $value): static
    {
        $this->formId = $value;
        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        // Join the campaigns table (just on id, like SmartLinks)
        $this->joinElementTable('campaignmanager_campaigns');

        // Select the columns from main table (non-translatable fields)
        $this->query->select([
            'campaignmanager_campaigns.campaignType',
            'campaignmanager_campaigns.formId',
            'campaignmanager_campaigns.invitationDelayPeriod',
            'campaignmanager_campaigns.invitationExpiryPeriod',
            'campaignmanager_campaigns.senderId',
        ]);

        if ($this->campaignType !== null) {
            $this->subQuery->andWhere(Db::parseParam('campaignmanager_campaigns.campaignType', $this->campaignType));
        }

        if ($this->formId !== null) {
            $this->subQuery->andWhere(Db::parseParam('campaignmanager_campaigns.formId', $this->formId));
        }

        return true;
    }
}
