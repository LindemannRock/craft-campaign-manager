<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\records\RecipientRecord;

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
    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Campaign::STATUS_ENABLED => [
                'elements.enabled' => true,
                'elements_sites.enabled' => true,
            ],
            Campaign::STATUS_DISABLED => [
                'elements_sites.enabled' => false,
            ],
            default => parent::statusCondition($status),
        };
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

        $recipientTable = RecipientRecord::tableName();

        // Select the columns from main table (non-translatable fields)
        $this->query->select([
            'campaignmanager_campaigns.campaignType',
            'campaignmanager_campaigns.formId',
            'campaignmanager_campaigns.invitationDelayPeriod',
            'campaignmanager_campaigns.invitationExpiryPeriod',
            'campaignmanager_campaigns.senderId',
            'precomputedSentCount' => (new Query())
                ->select('COUNT(*)')
                ->from(['recipients_sent' => $recipientTable])
                ->where('[[recipients_sent.campaignId]] = [[elements.id]]')
                ->andWhere('[[recipients_sent.siteId]] = [[elements_sites.siteId]]')
                ->andWhere([
                    'or',
                    ['and', ['not', ['recipients_sent.email' => null]], ['not', ['recipients_sent.email' => '']], ['not', ['recipients_sent.emailSendDate' => null]]],
                    ['and', ['not', ['recipients_sent.sms' => null]], ['not', ['recipients_sent.sms' => '']], ['not', ['recipients_sent.smsSendDate' => null]]],
                ]),
            'precomputedPendingCount' => (new Query())
                ->select('COUNT(*)')
                ->from(['recipients_pending' => $recipientTable])
                ->where('[[recipients_pending.campaignId]] = [[elements.id]]')
                ->andWhere('[[recipients_pending.siteId]] = [[elements_sites.siteId]]')
                ->andWhere([
                    'or',
                    ['and', ['not', ['recipients_pending.email' => null]], ['not', ['recipients_pending.email' => '']], ['recipients_pending.emailSendDate' => null]],
                    ['and', ['not', ['recipients_pending.sms' => null]], ['not', ['recipients_pending.sms' => '']], ['recipients_pending.smsSendDate' => null]],
                ]),
            'precomputedSubmissionCount' => (new Query())
                ->select('COUNT(*)')
                ->from(['recipients_submissions' => $recipientTable])
                ->where('[[recipients_submissions.campaignId]] = [[elements.id]]')
                ->andWhere('[[recipients_submissions.siteId]] = [[elements_sites.siteId]]')
                ->andWhere(['not', ['recipients_submissions.submissionId' => null]]),
            // Ensure we get the enabled status from elements_sites
            'elements_sites.enabled',
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
