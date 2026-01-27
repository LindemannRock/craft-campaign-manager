<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\services;

use Craft;
use craft\base\Component;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Service
 *
 * Wrapper for SMS Manager integration.
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     3.0.0
 */
class SmsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('campaign-manager');
    }

    /**
     * Send an SMS via SMS Manager
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string|null $language Message language ('en' or 'ar')
     * @param int|null $senderIdId Optional sender ID to use (uses default from settings if null)
     * @return bool
     */
    public function sendSms(string $to, string $message, ?string $language = 'en', ?int $senderIdId = null): bool
    {
        // Check if SMS Manager is available
        if (!$this->isSmsManagerAvailable()) {
            $this->logError('SMS Manager plugin is not installed or enabled');
            return false;
        }

        // Get sender ID from settings if not provided
        if ($senderIdId === null) {
            $senderIdId = CampaignManager::$plugin->getSettings()->defaultSenderIdId;
        }

        // Send via SMS Manager
        return SmsManager::$plugin->sms->send(
            to: $to,
            message: $message,
            language: $language ?? 'en',
            senderIdId: $senderIdId,
            sourcePlugin: 'campaign-manager',
        );
    }

    /**
     * Check if SMS Manager is installed and enabled
     */
    public function isSmsManagerAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled('sms-manager') &&
               Craft::$app->getPlugins()->isPluginEnabled('sms-manager');
    }

    /**
     * Get available sender IDs from SMS Manager
     *
     * @return array Array of sender IDs with id, name, and senderId
     */
    public function getAvailableSenderIds(): array
    {
        if (!$this->isSmsManagerAvailable()) {
            return [];
        }

        $senderIds = [];
        $records = SmsManager::$plugin->senderIds->getAllSenderIds();

        foreach ($records as $record) {
            if ($record->enabled) {
                $senderIds[] = [
                    'id' => $record->id,
                    'name' => $record->name,
                    'senderId' => $record->senderId,
                    'handle' => $record->handle,
                ];
            }
        }

        return $senderIds;
    }

    /**
     * Get sender ID options for select fields
     *
     * @return array Options array suitable for Craft select fields
     */
    public function getSenderIdOptions(): array
    {
        $options = [
            ['label' => Craft::t('campaign-manager', 'Use Default'), 'value' => ''],
        ];

        foreach ($this->getAvailableSenderIds() as $senderId) {
            $options[] = [
                'label' => $senderId['name'] . ' (' . $senderId['senderId'] . ')',
                'value' => $senderId['id'],
            ];
        }

        return $options;
    }
}
