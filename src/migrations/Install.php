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
        $this->createRecipientsTable();
        $this->createStatisticsTable();
        $this->createActivityLogsTable();
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
            'defaultProviderHandle' => $this->string(64),
            'defaultSenderIdHandle' => $this->string(64),
            'campaignTypeOptions' => $this->text(),
            'itemsPerPage' => $this->integer()->defaultValue(50),
            'logLevel' => $this->string()->defaultValue('error'),
            'enableActivityLogs' => $this->boolean()->notNull()->defaultValue(true),
            'activityLogsRetention' => $this->integer()->notNull()->defaultValue(30),
            'activityLogsLimit' => $this->integer()->notNull()->defaultValue(10000),
            'activityAutoTrimLogs' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Insert default settings row
        $this->insert($tableName, [
            'pluginName' => 'Campaign Manager',
            'invitationRoute' => 'campaign-manager/invitation',
            'itemsPerPage' => 50,
            'logLevel' => 'error',
            'enableActivityLogs' => true,
            'activityLogsRetention' => 30,
            'activityLogsLimit' => 10000,
            'activityAutoTrimLogs' => true,
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
            'providerHandle' => $this->string(64),
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
     * Create the recipients table
     */
    private function createRecipientsTable(): void
    {
        $tableName = '{{%campaignmanager_recipients}}';

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
     * Create the statistics table for analytics
     */
    private function createStatisticsTable(): void
    {
        $tableName = '{{%campaignmanager_analytics}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            // Delivery metrics
            'totalRecipients' => $this->integer()->notNull()->defaultValue(0),
            'emailsSent' => $this->integer()->notNull()->defaultValue(0),
            'smsSent' => $this->integer()->notNull()->defaultValue(0),
            // Engagement metrics
            'emailsOpened' => $this->integer()->notNull()->defaultValue(0),
            'smsOpened' => $this->integer()->notNull()->defaultValue(0),
            // Conversion metrics
            'submissions' => $this->integer()->notNull()->defaultValue(0),
            'expired' => $this->integer()->notNull()->defaultValue(0),
            // Standard timestamps
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create unique index for campaignId + siteId + date
        $this->createIndex(null, $tableName, ['campaignId', 'siteId', 'date'], true);

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
     * Create the activity logs table
     */
    private function createActivityLogsTable(): void
    {
        $tableName = '{{%campaignmanager_activity_logs}}';

        if ($this->db->tableExists($tableName)) {
            return;
        }

        $this->createTable($tableName, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->null(),
            'campaignId' => $this->integer()->null(),
            'recipientId' => $this->integer()->null(),
            'action' => $this->string(100)->notNull(),
            'source' => $this->string(50)->notNull()->defaultValue('system'),
            'summary' => $this->string(255),
            'details' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $tableName, ['userId']);
        $this->createIndex(null, $tableName, ['campaignId']);
        $this->createIndex(null, $tableName, ['recipientId']);
        $this->createIndex(null, $tableName, ['action']);
        $this->createIndex(null, $tableName, ['dateCreated']);

        $this->addForeignKey(
            null,
            $tableName,
            ['userId'],
            '{{%users}}',
            ['id'],
            'SET NULL'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['campaignId'],
            '{{%campaignmanager_campaigns}}',
            ['id'],
            'SET NULL'
        );

        $this->addForeignKey(
            null,
            $tableName,
            ['recipientId'],
            '{{%campaignmanager_recipients}}',
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
        $this->dropTableIfExists('{{%campaignmanager_activity_logs}}');
        $this->dropTableIfExists('{{%campaignmanager_analytics}}');
        $this->dropTableIfExists('{{%campaignmanager_recipients}}');
        $this->dropTableIfExists('{{%campaignmanager_campaigns_content}}');
        $this->dropTableIfExists('{{%campaignmanager_campaigns}}');
        $this->dropTableIfExists('{{%campaignmanager_settings}}');
    }
}
