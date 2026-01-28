<?php

/**
 * Campaign Manager plugin configuration file
 *
 * IMPORTANT: This config file acts as an OVERRIDE layer only
 * - Settings are stored in the database ({{%campaignmanager_settings}} table)
 * - Values defined here will override database settings (read-only)
 * - Settings overridden by this file cannot be changed in the Control Panel
 * - A warning will be displayed in the CP when a setting is overridden
 *
 * Multi-environment support:
 * - Use '*' for settings that apply to all environments
 * - Use 'dev', 'staging', 'production' for environment-specific overrides
 * - Environment-specific settings will be merged with '*' settings
 *
 * Copy this file to config/campaign-manager.php to use it
 *
 * @since 5.0.0
 */

use craft\helpers\App;

return [
    // ========================================
    // GLOBAL SETTINGS (All Environments)
    // ========================================
    '*' => [
        // ========================================
        // GENERAL SETTINGS
        // ========================================

        /**
         * Plugin name (displayed in Control Panel)
         * Default: 'Campaign Manager'
         */
        'pluginName' => 'Campaign Manager',

        /**
         * Log level for plugin operations
         * Options: 'debug', 'info', 'warning', 'error'
         * Default: 'error'
         */
        // 'logLevel' => 'error',

        /**
         * Number of items per page in CP listings
         * Default: 50
         */
        // 'itemsPerPage' => 50,

        // ========================================
        // CAMPAIGN SETTINGS
        // ========================================

        /**
         * Campaign type options for dropdown
         * Can be a simple list or key-value pairs
         * Default: null (no type filter)
         *
         * Examples:
         * Simple list: ['Survey', 'Newsletter', 'Event']
         * Key-value: ['survey' => 'Survey', 'newsletter' => 'Newsletter']
         */
        // 'campaignTypeOptions' => ['Survey', 'Newsletter', 'Event'],

        /**
         * Section handle to filter campaigns
         * Only entries from this section will be available as campaigns
         * Default: null (all sections)
         */
        // 'campaignSectionHandle' => 'surveys',

        // ========================================
        // INVITATION SETTINGS
        // ========================================

        /**
         * Route for invitation links
         * This is the URL path where recipients access their invitations
         * Example URL: https://yoursite.com/{invitationRoute}/{invitationCode}
         * Default: 'cm/invite'
         */
        // 'invitationRoute' => 'cm/invite',

        /**
         * Template to use for invitation pages
         * Path relative to templates folder
         * Default: null (uses plugin's default template)
         */
        // 'invitationTemplate' => '_campaign/invite',

        // ========================================
        // SMS INTEGRATION
        // ========================================

        /**
         * Default SMS Manager provider handle
         * Must match a provider handle from SMS Manager
         * Default: null (uses SMS Manager's default)
         */
        // 'defaultProviderHandle' => App::env('CAMPAIGN_SMS_PROVIDER'),

        /**
         * Default SMS Manager sender ID handle
         * Must match a sender ID handle from SMS Manager
         * Default: null (uses SMS Manager's default)
         */
        // 'defaultSenderIdHandle' => App::env('CAMPAIGN_SMS_SENDER'),
    ],

    // ========================================
    // DEVELOPMENT ENVIRONMENT
    // ========================================
    'dev' => [
        'logLevel' => 'debug',
        // Use test provider in development
        // 'defaultProviderHandle' => 'dev-provider',
        // 'defaultSenderIdHandle' => 'dev-sender',
    ],

    // ========================================
    // STAGING ENVIRONMENT
    // ========================================
    'staging' => [
        'logLevel' => 'info',
        // 'defaultProviderHandle' => 'staging-provider',
        // 'defaultSenderIdHandle' => 'staging-sender',
    ],

    // ========================================
    // PRODUCTION ENVIRONMENT
    // ========================================
    'production' => [
        'logLevel' => 'error',
        // 'defaultProviderHandle' => 'production-provider',
        // 'defaultSenderIdHandle' => 'main-sender',
    ],
];
