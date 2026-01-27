<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\records\Element;
use craft\records\Site;
use lindemannrock\campaignmanager\elements\Campaign;
use verbb\formie\records\Form;
use verbb\formie\records\Submission;

/**
 * Install migration
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): void
    {
        $this->createSettingsTable();
        $this->createCampaignsTable();
        $this->createCampaignsContentTable();
        $this->createCustomersTable();
    }

    /**
     * Create the settings table
     */
    private function createSettingsTable(): void
    {
        $tableName = '{{%campaignmanager_settings}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->primaryKey(),
            'pluginName' => $this->string()->defaultValue('Campaign Manager'),
            'campaignElementType' => $this->string(),
            'campaignSectionHandle' => $this->string(),
            'invitationRoute' => $this->string()->defaultValue('campaign-manager/invitation'),
            'invitationTemplate' => $this->string(),
            'defaultSenderIdId' => $this->integer(),
            'defaultCountryCode' => $this->string(2)->defaultValue('KW'),
            'campaignTypeOptions' => $this->text(),
            'logLevel' => $this->string()->defaultValue('error'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Insert default settings row
        $this->insert($tableName, [
            'pluginName' => 'Campaign Manager',
            'invitationRoute' => 'campaign-manager/invitation',
            'defaultCountryCode' => 'KW',
            'logLevel' => 'error',
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Create the campaigns table (non-translatable fields only)
     */
    private function createCampaignsTable(): void
    {
        $tableName = '{{%campaignmanager_campaigns}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->integer()->notNull(),
            'campaignType' => $this->string(),
            'formId' => $this->integer()->null(),
            'invitationDelayPeriod' => $this->string(),
            'invitationExpiryPeriod' => $this->string(),
            'senderId' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY ([[id]])',
        ]);

        // Create indexes
        $this->createIndex(null, $tableName, ['campaignType']);
        $this->createIndex(null, $tableName, ['formId']);

        // Add foreign keys
        $this->addForeignKey(
            null,
            $tableName,
            ['id'],
            Element::tableName(),
            ['id'],
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['formId'],
            Form::tableName(),
            ['id'],
            'SET NULL'
        );
    }

    /**
     * Create the campaigns content table (translatable fields)
     */
    private function createCampaignsContentTable(): void
    {
        $tableName = '{{%campaignmanager_campaigns_content}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'emailInvitationMessage' => $this->text(),
            'emailInvitationSubject' => $this->text(),
            'smsInvitationMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create unique index for campaignId + siteId
        $this->createIndex(null, $tableName, ['campaignId', 'siteId'], true);

        // Add foreign keys
        $this->addForeignKey(
            null,
            $tableName,
            ['campaignId'],
            '{{%campaignmanager_campaigns}}',
            ['id'],
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['siteId'],
            Site::tableName(),
            ['id'],
            'CASCADE'
        );
    }

    /**
     * Create the customers table
     */
    private function createCustomersTable(): void
    {
        $tableName = '{{%campaignmanager_customers}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'name' => $this->string(),
            'email' => $this->string(),
            'emailInvitationCode' => $this->string(),
            'emailSendDate' => $this->dateTime(),
            'emailOpenDate' => $this->dateTime(),
            'sms' => $this->string(),
            'smsInvitationCode' => $this->string(),
            'smsSendDate' => $this->dateTime(),
            'smsOpenDate' => $this->dateTime(),
            'submissionId' => $this->integer()->null(),
            'invitationExpiryDate' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes
        $this->createIndex(null, $tableName, ['campaignId', 'siteId']);
        $this->createIndex(null, $tableName, ['email']);
        $this->createIndex(null, $tableName, ['sms']);
        $this->createIndex(null, $tableName, ['emailInvitationCode']);
        $this->createIndex(null, $tableName, ['smsInvitationCode']);

        // Add foreign keys
        $this->addForeignKey(
            null,
            $tableName,
            ['campaignId'],
            '{{%campaignmanager_campaigns}}',
            ['id'],
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['siteId'],
            Site::tableName(),
            ['id'],
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['submissionId'],
            Submission::tableName(),
            ['id'],
            'SET NULL'
        );
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): void
    {
        // Delete all Campaign elements first
        $this->delete('{{%elements}}', ['type' => Campaign::class]);

        // Drop tables in reverse order due to foreign key constraints
        $this->dropTableIfExists('{{%campaignmanager_customers}}');
        $this->dropTableIfExists('{{%campaignmanager_campaigns_content}}');
        $this->dropTableIfExists('{{%campaignmanager_campaigns}}');
        $this->dropTableIfExists('{{%campaignmanager_settings}}');
    }
}
