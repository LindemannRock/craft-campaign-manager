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
use craft\elements\User;
use craft\helpers\Html;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\tests\TestCase;
use verbb\formie\elements\Form;
use verbb\formie\Formie;

/**
 * Pins the Campaign element-index "Form" column to a safely encoded link.
 *
 * Craft's element index renders `getAttributeHtml()` output unescaped, so the
 * Formie form title and its CP edit URL must be encoded by the element itself.
 * Formie accepts any string of up to 255 characters as a form title, including
 * HTML, so an authored title must render as text rather than as markup. A user
 * to whom Formie offers no edit URL sees the title as plain text, not as a
 * link to nowhere.
 *
 * @since 5.16.0
 */
final class ElementIndexFormLinkEscapingTest extends TestCase
{
    public function testMarkupInFormTitleRendersAsText(): void
    {
        $title = $this->nextTestMarker('Campaign Manager test ', 'form')
            . ' <script>alert("cm")</script><img src=x onerror="alert(1)">';
        $form = $this->renameFormThroughValidatedSave($this->seedForm(['name']), $title);
        $this->actAsFormieFormManager();
        $editUrl = $this->formieEditUrl($form);

        $html = $this->seedCampaign($form)->getAttributeHtml('form');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertSame($this->expectedLink($editUrl, $title), $html);
    }

    public function testOrdinaryTitleRendersAsLinkedText(): void
    {
        $form = $this->seedForm(['name']);
        $this->actAsFormieFormManager();
        $editUrl = $this->formieEditUrl($form);

        $html = $this->seedCampaign($form)->getAttributeHtml('form');

        self::assertStringContainsString('>' . $form->title . '<', $html);
        self::assertSame($this->expectedLink($editUrl, (string)$form->title), $html);
    }

    public function testSpecialCharactersInTitleAreEncodedOnce(): void
    {
        $title = $this->nextTestMarker('Campaign Manager test ', 'form') . ' Tom & Jerry\'s "Q&A" <2026>';
        $form = $this->renameFormThroughValidatedSave($this->seedForm(['name']), $title);
        $this->actAsFormieFormManager();
        $editUrl = $this->formieEditUrl($form);

        $html = $this->seedCampaign($form)->getAttributeHtml('form');

        self::assertStringContainsString('Tom &amp; Jerry&#039;s &quot;Q&amp;A&quot; &lt;2026&gt;', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertSame($this->expectedLink($editUrl, $title), $html);
    }

    public function testTitleRendersAsPlainTextWhenTheUserMayNotEditFormieForms(): void
    {
        $form = $this->seedForm(['name']);
        $this->actAsCampaignManagerUserWithoutFormieAccess();
        self::assertNull($form->getCpEditUrl(), 'Formie offers no edit URL to this user.');

        $html = $this->seedCampaign($form)->getAttributeHtml('form');

        self::assertSame(Html::encode((string)$form->title), $html, 'Without an edit URL the title is text, not an empty link.');
        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('href', $html);
    }

    public function testMarkupInFormTitleRendersAsTextWithoutALink(): void
    {
        $title = $this->nextTestMarker('Campaign Manager test ', 'form')
            . ' <script>alert("cm")</script><img src=x onerror="alert(1)">';
        $form = $this->renameFormThroughValidatedSave($this->seedForm(['name']), $title);
        $this->actAsCampaignManagerUserWithoutFormieAccess();

        $html = $this->seedCampaign($form)->getAttributeHtml('form');

        self::assertSame(Html::encode($title), $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<a', $html);
    }

    public function testCampaignWithoutFormRendersPlaceholder(): void
    {
        self::assertSame('—', (new Campaign())->getAttributeHtml('form'));
    }

    public function testCampaignWhoseFormWasDeletedRendersPlaceholder(): void
    {
        $form = $this->seedForm(['name']);
        $campaign = $this->seedCampaign($form);
        $this->actAsFormieFormManager();

        if (!Craft::$app->getElements()->deleteElement($form)) {
            self::fail('Formie form fixture could not be deleted: ' . json_encode($form->getErrors()));
        }

        $reloaded = Campaign::find()->id($campaign->id)->one();
        self::assertInstanceOf(Campaign::class, $reloaded);
        self::assertSame((int)$form->id, (int)$reloaded->formId);

        self::assertSame('—', $reloaded->getAttributeHtml('form'));
    }

    /**
     * Rename a form the way Formie's save action does: assign the posted title
     * and run the full element validation. Returns the persisted form.
     */
    private function renameFormThroughValidatedSave(Form $form, string $title): Form
    {
        $form->title = $title;
        $this->saveTestElement($form, true, true, false);

        $persisted = Formie::$plugin->getForms()->getFormById((int)$form->id);
        self::assertInstanceOf(Form::class, $persisted);
        self::assertSame($title, $persisted->title);

        return $persisted;
    }

    /**
     * Formie only exposes a CP edit URL to users who may manage forms. The
     * manage permission is nested under the access permission, so both are
     * granted, as the CP permission tree does.
     */
    private function actAsFormieFormManager(): User
    {
        $user = $this->createTestUser('Campaign Manager test ');
        $this->grantPermissions($user, ['formie-accessForms', 'formie-manageForms']);
        $this->actingAs($user);

        return $user;
    }

    /**
     * A Campaign Manager user who may manage campaigns but holds no Formie
     * permission; Formie offers such a user no form edit URL.
     */
    private function actAsCampaignManagerUserWithoutFormieAccess(): User
    {
        $user = $this->createTestUser('Campaign Manager test ');
        $this->grantPermissions($user, ['accessCp', 'campaignManager:manageCampaigns']);
        $this->actingAs($user);
        self::assertFalse($user->can('formie-manageForms'));

        return $user;
    }

    private function formieEditUrl(Form $form): string
    {
        $editUrl = $form->getCpEditUrl();
        self::assertIsString($editUrl);
        self::assertStringContainsString('formie/forms/edit/' . $form->id, $editUrl);

        return $editUrl;
    }

    private function expectedLink(string $editUrl, string $title): string
    {
        return '<a href="' . Html::encode($editUrl) . '">' . Html::encode($title) . '</a>';
    }
}
