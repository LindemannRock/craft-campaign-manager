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
use craft\web\View;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Support\SiteRestrictedUserTestCase;
use Twig\Cache\CacheInterface;

/**
 * The Code column of a campaign's recipient list shows the invitation code
 * of each contact channel the recipient actually has: the email code for an
 * email address, the SMS code for a phone number, both with their channel
 * named when the recipient has both, and one code once when a recipient
 * from before the channels had separate codes shares it between them.
 * Stored codes and the links built from them are not touched.
 *
 * @since 5.16.0
 */
final class RecipientListInvitationCodeDisplayTest extends SiteRestrictedUserTestCase
{
    private const PARTIAL = 'campaign-manager/recipients/_partials/invitation-codes';

    private CacheInterface|string|false|null $originalTwigCache = null;

    protected function cleanupExternalState(): void
    {
        try {
            if ($this->originalTwigCache !== null) {
                Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_CP)->setCache($this->originalTwigCache);
                $this->originalTwigCache = null;
            }
        } finally {
            parent::cleanupExternalState();
        }
    }

    public function testTheRecipientListShowsCodesThroughThePartial(): void
    {
        $list = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/recipients/list.twig');

        self::assertStringContainsString("include 'campaign-manager/recipients/_partials/invitation-codes'", $list);
        self::assertStringNotContainsString('item.smsInvitationCode ?? item.emailInvitationCode', $list, 'The list no longer prefers one channel\'s code over the other.');
    }

    public function testAnEmailOnlyRecipientShowsTheEmailCode(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => null]);
        self::assertNotNull($recipient->smsInvitationCode, 'A stored SMS code exists even without a phone number.');

        $html = $this->render($recipient);

        self::assertSame([$recipient->emailInvitationCode], $this->codesIn($html));
        self::assertStringNotContainsString((string)$recipient->smsInvitationCode, $html, 'A code for a channel the recipient does not have is not shown.');
        self::assertStringNotContainsString('Email', $html, 'One channel needs no label.');
    }

    public function testAnSmsOnlyRecipientShowsTheSmsCode(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['email' => null, 'sms' => '+96597176606']);

        $html = $this->render($recipient);

        self::assertSame([$recipient->smsInvitationCode], $this->codesIn($html));
        self::assertStringNotContainsString((string)$recipient->emailInvitationCode, $html);
    }

    public function testADualChannelRecipientShowsBothCodesWithTheirChannels(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => '+96597176606']);
        self::assertNotSame($recipient->emailInvitationCode, $recipient->smsInvitationCode);

        $html = $this->render($recipient);

        self::assertSame([$recipient->emailInvitationCode, $recipient->smsInvitationCode], $this->codesIn($html));
        $text = (string)preg_replace('/\s+/', ' ', strip_tags($html));
        self::assertStringContainsString('Email: ' . $recipient->emailInvitationCode, $text);
        self::assertStringContainsString('SMS: ' . $recipient->smsInvitationCode, $text);
    }

    public function testARecipientWhoseChannelsShareOneCodeShowsItOnce(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => '+96597176606']);
        $this->storeCodesAsFound($recipient, 'SHARED1234', 'SHARED1234');

        $html = $this->render($this->reloadRecipient($recipient));

        self::assertSame(['SHARED1234'], $this->codesIn($html), 'A legacy shared code is shown once, not twice.');
        self::assertStringNotContainsString('Email:', strip_tags($html));
    }

    public function testARecipientWithoutAnyCodeShowsAPlaceholder(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => '+96597176606']);
        $this->storeCodesAsFound($recipient, null, null);

        $html = $this->render($this->reloadRecipient($recipient));

        self::assertSame([], $this->codesIn($html));
        self::assertStringContainsString('-', strip_tags($html));
    }

    public function testAnEmailRecipientWithoutAnEmailCodeShowsAPlaceholder(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => null]);
        $this->storeCodesAsFound($recipient, null, (string)$recipient->smsInvitationCode);

        $html = $this->render($this->reloadRecipient($recipient));

        self::assertSame([], $this->codesIn($html), 'The SMS code is not shown in place of a missing email code.');
    }

    public function testCodesAreRenderedAsText(): void
    {
        [$siteA] = $this->siteIds(1);
        $recipient = $this->seedOwnedRecipient($this->seedOwnedCampaign(), $siteA, ['sms' => '+96597176606']);
        $this->storeCodesAsFound($recipient, '<script>alert("e")</script>', '<img src=x onerror="alert(1)">');

        $html = $this->render($this->reloadRecipient($recipient));

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    /**
     * Store codes on the recipient exactly as a database row carries them,
     * bypassing the record, which would generate missing codes.
     */
    private function storeCodesAsFound(RecipientRecord $recipient, ?string $emailCode, ?string $smsCode): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(RecipientRecord::tableName(), ['emailInvitationCode' => $emailCode, 'smsInvitationCode' => $smsCode], ['id' => $recipient->id])
            ->execute();
    }

    /**
     * Render the Code cell for the recipient, compiling in memory so no
     * compiled template is left behind.
     */
    private function render(RecipientRecord $recipient): string
    {
        $twig = Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_CP);
        $this->originalTwigCache ??= $twig->getCache();
        $twig->setCache(false);

        return Craft::$app->getView()->renderTemplate(self::PARTIAL, ['item' => $recipient], View::TEMPLATE_MODE_CP);
    }

    /**
     * The codes rendered, in order.
     *
     * @return list<string>
     */
    private function codesIn(string $html): array
    {
        preg_match_all('/<code[^>]*>(.*?)<\/code>/s', $html, $matches);

        return array_values(array_map(static fn(string $code): string => html_entity_decode(trim($code)), $matches[1]));
    }
}
