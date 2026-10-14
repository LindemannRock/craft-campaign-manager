<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use Craft;
use craft\helpers\DateTimeHelper;
use DateTime;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\tests\Stubs\InvitationRequest;
use lindemannrock\campaignmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * Render the shipped guard through the project's supported include shape.
 * A private in-memory Twig environment owns compilation; the form renderer
 * is a marker so this guard test does not register unrelated Formie assets.
 *
 * @since 5.16.0
 */
final class InvitationPageValidityTest extends TestCase
{
    /** @return array<string, array{string, bool, string}> */
    public static function states(): array
    {
        return [
            'manual link before send' => ['2026-03-15 08:59:59', false, 'Welcome! Please complete the form below.'],
            'at expiry' => ['2026-03-15 09:00:00', false, 'This invitation has expired.'],
            'after expiry' => ['2026-03-15 09:00:01', false, 'This invitation has expired.'],
            'already answered takes precedence' => ['2026-03-15 09:00:01', true, 'You have already responded to this invitation.'],
        ];
    }

    #[DataProvider('states')]
    public function testShippedTemplateEnforcesStoredValidityThroughTheProjectInclude(string $now, bool $answered, string $message): void
    {
        DateTimeHelper::pause(new DateTime('2026-03-01 09:00:00'));
        try {
            $form = $this->seedForm(['feedback']);
            $recipient = $this->seedRecipient($this->seedCampaign($form, ['invitationExpiryPeriod' => 'P14D']));
            if ($answered) {
                $this->submitForm($form, (string)$recipient->emailInvitationCode);
            }
            DateTimeHelper::pause(new DateTime($now));
            try {
                $formRenderer = new class() {
                    public int $calls = 0;

                    public function renderForm(mixed $form): string
                    {
                        $this->calls++;
                        return 'INVITATION_FORM';
                    }
                };
                $twig = new Environment(new ArrayLoader([
                    'survey' => "{% include 'campaign-manager/invite' %}",
                    'campaign-manager/invite' => (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/invite.twig'),
                ]), ['cache' => false]);
                $twig->addFilter(new TwigFilter('t', static fn(string $value, string $category): string => Craft::t($category, $value, [], 'en')));
                $html = $twig->render('survey', ['craft' => (object)[
                    'app' => (object)['language' => 'en', 'request' => new InvitationRequest(['code' => $recipient->emailInvitationCode])],
                    'campaignManager' => CampaignManager::$plugin,
                    'formie' => $formRenderer,
                ]]);
                self::assertStringContainsString($message, $html);
                $valid = !$answered && $now < '2026-03-15 09:00:00';
                self::assertSame($valid ? 1 : 0, $formRenderer->calls);
                self::assertSame($valid, $this->reloadRecipient($recipient)->emailOpenDate !== null);
                self::assertNull($this->reloadRecipient($recipient)->smsOpenDate);
            } finally {
                DateTimeHelper::resume();
            }
        } finally {
            DateTimeHelper::resume();
        }
    }
}
