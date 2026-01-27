# Campaign Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-campaign-manager.svg)](https://packagist.org/packages/lindemannrock/craft-campaign-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Formie](https://img.shields.io/badge/Formie-3.0%2B-purple.svg)](https://verbb.io/craft-plugins/formie)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-campaign-manager.svg)](LICENSE)

Campaign management for customer surveys with SMS and email invitations for Craft CMS 5.x.

## Beta Notice

This plugin is currently in active development and provided under the MIT License for testing purposes.

**Licensing is subject to change.** We are finalizing our licensing structure and some or all features may require a paid license when officially released on the Craft Plugin Store.

## Features

- **Campaign Management**: Create and manage survey campaigns linked to Formie forms
  - Multi-site support with site-specific customers
  - Campaign types for organization
  - Configurable invitation delay and expiry periods
- **Customer Management**:
  - Import customers from CSV files
  - Add individual customers manually
  - Track invitation status (sent, opened, submitted)
  - Unique invitation codes per customer
  - Export customers to CSV/JSON
- **Multi-Channel Invitations**:
  - SMS invitations via [SMS Manager](https://github.com/LindemannRock/craft-sms-manager)
  - Email invitations with customizable templates
  - Bitly URL shortening for SMS links
- **Queue-Based Processing**:
  - Batch processing for large customer lists
  - Background job execution
  - Progress tracking
- **Survey Response Tracking**:
  - Link Formie submissions to customers
  - Track survey completion rates
  - Invitation expiry handling
- **Comprehensive Analytics**:
  - Customer counts per campaign
  - Submission tracking
  - Date range filtering
- **User Permissions**: Granular access control for campaigns, customers, and settings
- **Logging**: Structured logging via Logging Library with configurable levels

## Requirements

- Craft CMS 5.0 or greater
- PHP 8.2 or greater
- [Formie](https://verbb.io/craft-plugins/formie) 3.0 or greater
- [SMS Manager](https://github.com/LindemannRock/craft-sms-manager) 5.0 or greater (for SMS invitations)
- [Logging Library](https://github.com/LindemannRock/craft-logging-library) 5.0 or greater (installed automatically)
- [Plugin Base](https://github.com/LindemannRock/craft-plugin-base) 5.0 or greater (installed automatically)

## Installation

### Via Composer (Development)

Until published on Packagist, install directly from the repository:

```bash
cd /path/to/project
composer config repositories.campaign-manager vcs https://github.com/LindemannRock/craft-campaign-manager
composer require lindemannrock/craft-campaign-manager:dev-main
./craft plugin/install campaign-manager
```

### Via Composer (Production - Coming Soon)

Once published on Packagist:

```bash
cd /path/to/project
composer require lindemannrock/craft-campaign-manager
./craft plugin/install campaign-manager
```

### Via Plugin Store (Future)

1. Go to the Plugin Store in your Craft control panel
2. Search for "Campaign Manager"
3. Click "Install"

## Configuration

### Settings

Navigate to **Survey Campaigns → Settings** in the control panel to configure:

**General Settings:**
- **Plugin Name**: Customize the display name in the control panel
- **Campaign Section Handle**: The section handle where campaigns are stored

**Bitly Settings:**
- **Bitly API Key**: API key for URL shortening (environment variable recommended)

**Logging Settings:**
- **Log Level**: debug, info, warning, error

### Environment Variables

```bash
# .env
BITLY_API_KEY=your-bitly-api-key
```

### Config File

Create a `config/campaign-manager.php` file to override default settings:

```php
<?php
return [
    // Plugin Settings
    'pluginName' => 'Survey Campaigns',
    'campaignSectionHandle' => 'campaigns',

    // Logging Settings
    'logLevel' => 'error',

    // Multi-environment support
    'dev' => [
        'logLevel' => 'debug',
    ],
    'production' => [
        'logLevel' => 'error',
    ],
];
```

## Setup

### 1. Create Campaign Section

Create a Craft section for campaigns with the following fields:

- **Campaign Type** (Dropdown): Type categorization
- **Form** (Formie Form): The survey form
- **Invitation Delay Period** (Text): ISO 8601 duration (e.g., `P1D` for 1 day)
- **Invitation Expiry Period** (Text): ISO 8601 duration (e.g., `P30D` for 30 days)
- **SMS Invitation Message** (Plain Text): SMS template with `{survey_link}` and `{customer_name}` tokens
- **Email Invitation Subject** (Plain Text): Email subject line
- **Email Invitation Message** (Rich Text): Email template with tokens
- **Sender ID** (Text): SMS sender ID handle
- **Surveys Welcome** (Rich Text): Message shown before survey
- **Surveys Already Responded** (Rich Text): Message for completed surveys
- **Surveys Invitation Expired** (Rich Text): Message for expired invitations

### 2. Configure Plugin Settings

1. Navigate to **Survey Campaigns → Settings**
2. Set the **Campaign Section Handle** to match your section
3. Configure Bitly API key if using SMS invitations

### 3. Create Survey Template

Create a template for the survey page (e.g., `templates/survey.twig`):

```twig
{% extends '_layouts/surveys.twig' %}

{% block content %}
    {% set invitationCode = craft.app.request.getQueryParam('invitationCode') %}

    {% if invitationCode %}
        {% set customer = campaignManager.customers.getCustomerByInvitationCode(invitationCode) %}
        {% set campaign = customer.getCampaign() %}

        {% if customer.hasSubmission() %}
            {{ campaign.surveysAlreadyResponded|raw }}
        {% elseif customer.invitationIsExpired() %}
            {{ campaign.surveysInvitationExpired|raw }}
        {% else %}
            {{ campaign.surveysWelcome|raw }}
            {{ craft.formie.renderForm(campaign.getForm()) }}
        {% endif %}
    {% else %}
        <p>Invalid invitation code.</p>
    {% endif %}
{% endblock %}
```

## Usage

### Managing Campaigns

1. Navigate to **Survey Campaigns** in the control panel
2. Click **New Campaign** to create a campaign entry
3. Configure the campaign settings and associated form
4. Save the campaign

### Adding Customers

#### Single Customer

1. Navigate to the campaign's customer list
2. Click **Add → New Customer**
3. Enter customer details (name, email, phone, site)
4. Save

#### Import from CSV

1. Navigate to the campaign's customer list
2. Click **Add → Import from CSV**
3. Upload a CSV file with columns:
   - `Name` (required)
   - `Email` (optional)
   - `Phone` (optional)
   - `Language` (optional: `en` or `ar`)
4. Choose whether to send invitations after import
5. Click Import

**CSV Format Example:**
```csv
Name,Email,Phone,Language
John Doe,john@example.com,96512345678,en
Ahmed Ali,ahmed@example.com,96598765432,ar
```

### Running Campaigns

#### Single Campaign

1. Navigate to the campaign's customer list
2. Click **Run Campaign**
3. Invitations will be queued and sent in batches

#### All Campaigns

1. Navigate to **Survey Campaigns**
2. Click **Run All**
3. All campaigns will be processed

### Exporting Customers

1. Navigate to the campaign's customer list
2. Click **Export**
3. Choose format (CSV or JSON)
4. Download includes all customer data and status

## Template Variables

### campaignManager.campaigns

```twig
{# Get all campaigns #}
{% set campaigns = campaignManager.campaigns.find().all() %}

{# Get campaign by ID #}
{% set campaign = campaignManager.campaigns.find().id(123).one() %}

{# Get campaigns for a site #}
{% set campaigns = campaignManager.campaigns.find().site('en').all() %}
```

### campaignManager.customers

```twig
{# Get customer by invitation code #}
{% set customer = campaignManager.customers.getCustomerByInvitationCode(code) %}

{# Mark customer as opened #}
{% do campaignManager.customers.markAsOpened(customer) %}

{# Check customer status #}
{% if customer.hasSubmission() %}
    {# Already submitted #}
{% elseif customer.invitationIsExpired() %}
    {# Invitation expired #}
{% endif %}
```

## Console Commands

```bash
# Run all campaigns
./craft campaign-manager/campaigns/run-all

# Run specific campaign
./craft campaign-manager/campaigns/run --campaign=123
```

## Permissions

### Campaign Permissions
- **Manage campaigns**
  - View campaigns
  - Create campaigns
  - Edit campaigns
  - Delete campaigns

### Customer Permissions
- **Manage customers**
  - View customers
  - Create customers
  - Import customers
  - Delete customers
  - Export customers

### Settings Permissions
- **Manage settings**

## Events

```php
use lindemannrock\campaignmanager\services\CustomersService;
use lindemannrock\campaignmanager\events\CustomerEvent;
use yii\base\Event;

// Before sending invitation
Event::on(
    CustomersService::class,
    CustomersService::EVENT_BEFORE_SEND_INVITATION,
    function(CustomerEvent $event) {
        // Access: $event->customer, $event->campaign
        // Set $event->isValid = false to cancel
    }
);

// After sending invitation
Event::on(
    CustomersService::class,
    CustomersService::EVENT_AFTER_SEND_INVITATION,
    function(CustomerEvent $event) {
        // Access: $event->customer, $event->success
    }
);
```

## Troubleshooting

### Invitations Not Sending

1. **Check SMS Manager is configured**: Ensure providers and sender IDs are set up
2. **Check Bitly API key**: Required for SMS URL shortening
3. **Check queue is running**: `./craft queue/run`
4. **Check logs**: Survey Campaigns → System Logs

### Survey Page Not Loading

1. **Verify invitation code**: Check the URL has a valid `invitationCode` parameter
2. **Check customer exists**: The invitation code must match a customer record
3. **Check campaign has form**: The campaign must have a Formie form assigned

### CSV Import Failing

1. **Check CSV format**: Must have `Name` column at minimum
2. **Check encoding**: Use UTF-8 encoding for special characters
3. **Check file size**: Large files are processed in batches

### Bitly URLs Not Working

1. **Verify API key**: Check `BITLY_API_KEY` environment variable
2. **Check API limits**: Bitly has rate limits on free plans
3. **Fallback**: If Bitly fails, original URLs are used

## Logging

Survey Campaigns uses the [LindemannRock Logging Library](https://github.com/LindemannRock/craft-logging-library) for system logging.

### Log Levels
- **Error**: Critical errors only (default)
- **Warning**: Errors and warnings
- **Info**: General information
- **Debug**: Detailed debugging (requires devMode)

### Log Files
- **Location**: `storage/logs/campaign-manager-YYYY-MM-DD.log`
- **Retention**: 30 days (automatic cleanup)
- **Web Interface**: View logs at Survey Campaigns → System Logs

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-campaign-manager](https://github.com/LindemannRock/craft-campaign-manager)
- **Issues**: [https://github.com/LindemannRock/craft-campaign-manager/issues](https://github.com/LindemannRock/craft-campaign-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the MIT License. See [LICENSE](LICENSE) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)
