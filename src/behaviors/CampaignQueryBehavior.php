<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\behaviors;

use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\events\CancelableEvent;
use craft\helpers\Db;
use lindemannrock\campaignmanager\elements\db\CampaignQuery;
use lindemannrock\campaignmanager\records\CampaignRecord;
use yii\base\Behavior;

/**
 * Campaign Query Behavior
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 *
 * @property ElementQuery $owner
 */
class CampaignQueryBehavior extends Behavior
{
    public ?string $campaignType = null;

    public ?bool $hasCampaign = null;

    /**
     * @inheritdoc
     */
    public function events(): array
    {
        return [
            ElementQuery::EVENT_AFTER_PREPARE => fn(CancelableEvent $event) => $this->handleAfterPrepare($event),
        ];
    }

    /**
     * Handle after prepare event
     */
    protected function handleAfterPrepare(CancelableEvent $event): void
    {
        /** @var ElementQuery $query */
        $query = $event->sender;

        // Skip Campaign element queries - they handle their own joins
        if ($query instanceof CampaignQuery) {
            return;
        }

        // Join only on id (siteId is now in content table)
        $joinTable = CampaignRecord::tableName() . ' cmCampaigns';
        $query->query->leftJoin($joinTable, '[[cmCampaigns.id]] = [[subquery.elementsId]]');
        $query->subQuery->leftJoin($joinTable, '[[cmCampaigns.id]] = [[elements.id]]');

        if ($this->hasCampaign !== null) {
            $query->subQuery->andWhere([($this->hasCampaign ? 'is not' : 'is'), '[[cmCampaigns.id]]', null]);
        }

        if ($this->campaignType !== null) {
            $query->subQuery->andWhere(Db::parseParam('cmCampaigns.campaignType', $this->campaignType));
        }
    }

    /**
     * Filter by campaign type
     */
    public function campaignType(?string $value): ElementQueryInterface
    {
        $this->campaignType = $value;
        return $this->owner;
    }

    /**
     * Filter by having a campaign
     */
    public function hasCampaign(?bool $value = true): ElementQueryInterface
    {
        $this->hasCampaign = $value;
        return $this->owner;
    }
}
