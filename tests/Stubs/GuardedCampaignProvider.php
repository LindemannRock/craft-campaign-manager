<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use lindemannrock\smsmanager\models\TransportContext;
use lindemannrock\smsmanager\providers\BaseProvider;
use lindemannrock\smsmanager\providers\TransportProviderInterface;
use lindemannrock\smsmanager\SmsManager;

/**
 * Optional custom-provider contract without any network implementation.
 *
 * @since 5.18.1
 */
final class GuardedCampaignProvider extends BaseProvider implements TransportProviderInterface
{
    public static function handle(): string
    {
        return 'campaign-synthetic';
    }

    public static function displayName(): string
    {
        return 'Synthetic provider';
    }

    public static function description(): string
    {
        return 'Synthetic provider';
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function getTransportContext(array $settings): TransportContext
    {
        return new TransportContext(self::handle(), 'campaign-synthetic', ['main' => $settings['account']]);
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        return SmsManager::$plugin->transport->send($this->getTransportContext($settings), static fn(): array => [
            'success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account',
        ]);
    }
}
