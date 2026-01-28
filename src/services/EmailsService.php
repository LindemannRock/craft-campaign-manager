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
use craft\helpers\App;
use craft\mail\Message;
use craft\web\View;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\helpers\TimeHelper;
use lindemannrock\campaignmanager\records\CampaignRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use Throwable;

/**
 * Emails Service
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class EmailsService extends Component
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
     * Send a notification email to a recipient
     *
     * @since 5.0.0
     */
    public function sendNotificationEmail(RecipientRecord $recipient, CampaignRecord $campaign): bool
    {
        try {
            $message = $this->getMessage($recipient, $campaign);
            $result = $this->sendEmail($message);

            if ($result) {
                $recipient->emailSendDate = TimeHelper::now();
                $recipient->save(false);
            }

            return $result;
        } catch (Throwable $e) {
            $this->logError('Failed to build email message', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Build the email message
     */
    private function getMessage(RecipientRecord $recipient, CampaignRecord $campaign): Message
    {
        // Get content record for translatable fields
        $contentRecord = $campaign->getContentForSite($recipient->siteId);
        $emailInvitationMessageRaw = $contentRecord?->emailInvitationMessage;
        $emailInvitationSubject = $contentRecord?->emailInvitationSubject ?? '';

        $emailInvitationMessage = null;
        if (!empty($emailInvitationMessageRaw)) {
            $decoded = json_decode($emailInvitationMessageRaw, true);
            if (is_array($decoded)) {
                $emailInvitationMessage = $decoded;
            }
        }

        $emailMessage = $emailInvitationMessage['form'] ?? $emailInvitationMessageRaw;
        $campaignElement = $recipient->getCampaign();

        // Build the invitation URL from the plugin's invitation route setting
        $settings = CampaignManager::$plugin->getSettings();
        $invitationRoute = $settings->invitationRoute ?? 'campaign-manager/invitation';

        // Get the recipient's site base URL
        $recipientSite = Craft::$app->getSites()->getSiteById($recipient->siteId);
        $baseUrl = $recipientSite?->getBaseUrl() ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        // Build full invitation URL with code
        $invitationUrl = rtrim($baseUrl, '/') . '/' . ltrim($invitationRoute, '/') . '?code=' . $recipient->emailInvitationCode;
        $shortenedUrl = CampaignManager::$plugin->recipients->getBitlyUrl($invitationUrl);

        // Get language from recipient's site
        $site = Craft::$app->getSites()->getSiteById($recipient->siteId);
        $language = $site ? strtolower(substr($site->language, 0, 2)) : 'en';

        $variables = [
            'recipient_name' => $recipient->name,
            'invitationUrl' => $shortenedUrl,
            'survey_link' => $shortenedUrl, // backwards compatibility
            'defaultLanguage' => $language,
        ];

        $email = $recipient->email;
        $view = Craft::$app->getView();

        $message = new Message();
        $senderName = App::env('SYSTEM_SENDER_NAME') ?? App::env('SYSTEM_EMAIL') ?? 'Survey';
        $message->setFrom([App::env('SYSTEM_EMAIL') => $senderName]);

        // Render subject and body with variable substitution
        $subject = $view->renderObjectTemplate($emailInvitationSubject, $recipient, $variables);
        $textBody = $view->renderObjectTemplate($emailMessage ?? '', $recipient, $variables);
        $variables['body'] = $textBody;

        // Render HTML template
        $template = '_emails/craft/index';
        $htmlBody = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);

        $message->setSubject($subject);
        $message->setHtmlBody($htmlBody);
        $message->setTextBody(strip_tags($textBody));
        $message->setReplyTo(App::env('SYSTEM_EMAIL_REPLY_TO'));
        $message->setTo([trim($email)]);

        return $message;
    }

    /**
     * Send an email message
     */
    private function sendEmail(Message $message): bool
    {
        $mailer = Craft::$app->getMailer();
        $result = false;

        try {
            $result = $mailer->send($message);
        } catch (Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $this->logError('Failed to send campaign email', ['error' => $e->getMessage()]);
        }

        if ($result) {
            $this->logInfo('Campaign email sent successfully');
        } else {
            $this->logError('Unable to send campaign email');
        }

        return $result;
    }
}
