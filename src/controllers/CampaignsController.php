<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\campaignmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\campaignmanager\CampaignManager;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\ProcessCampaignJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Campaigns Controller
 *
 * @author    LindemannRock
 * @package   CampaignManager
 * @since     5.0.0
 */
class CampaignsController extends Controller
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(CampaignManager::$plugin->id);
    }

    /**
     * Campaign index page (element index)
     *
     * If user doesn't have permission for campaigns, redirect to first accessible section
     *
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();

        // If user doesn't have viewCampaigns permission, redirect to first accessible section
        if (!$user->checkPermission('campaignManager:viewCampaigns')) {
            if ($user->checkPermission('campaignManager:viewRecipients')) {
                return $this->redirect('campaign-manager/recipients');
            }
            if ($user->checkPermission('campaignManager:viewLogs')) {
                return $this->redirect('campaign-manager/logs/system');
            }
            if ($user->checkPermission('campaignManager:manageSettings')) {
                return $this->redirect('campaign-manager/settings');
            }

            // No accessible sections - throw forbidden
            throw new ForbiddenHttpException('You do not have permission to access this area.');
        }

        return $this->renderTemplate('campaign-manager/campaigns/index', [
            'elementType' => Campaign::class,
        ]);
    }

    /**
     * Edit a campaign
     *
     * @since 5.0.0
     */
    public function actionEdit(?int $campaignId = null, ?Campaign $campaign = null): Response
    {
        // Get site from request or use current
        $siteHandle = Craft::$app->getRequest()->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                throw new NotFoundHttpException('Invalid site handle: ' . $siteHandle);
            }
            $siteId = $site->id;
        } else {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        // Get the campaign
        if ($campaign === null) {
            if ($campaignId !== null) {
                $campaign = Campaign::find()
                    ->id($campaignId)
                    ->siteId($siteId)
                    ->status(null)
                    ->one();

                if (!$campaign) {
                    throw new NotFoundHttpException('Campaign not found');
                }

                $this->requirePermission('campaignManager:editCampaigns');
            } else {
                $this->requirePermission('campaignManager:createCampaigns');

                $campaign = new Campaign();
                $campaign->siteId = $siteId;

                // Set defaults from plugin settings for new campaigns
                $settings = CampaignManager::$plugin->getSettings();
                $campaign->providerHandle = $settings->defaultProviderHandle;
                $campaign->senderId = $settings->defaultSenderIdHandle;
            }
        }

        // Check if the user can view/edit this campaign
        if ($campaign->id && !Craft::$app->getUser()->checkPermission('campaignManager:viewCampaigns')) {
            throw new ForbiddenHttpException('You don\'t have permission to view this campaign.');
        }

        // Get available forms for dropdown
        $formOptions = [];
        if (PluginHelper::isPluginEnabled('formie')) {
            $formElements = \verbb\formie\elements\Form::find()->all();
            foreach ($formElements as $form) {
                $formOptions[] = ['label' => $form->title, 'value' => $form->id];
            }
        }

        // Get campaign type options
        $settings = CampaignManager::$plugin->getSettings();
        $campaignTypeOptions = $settings->getCampaignTypeOptions();

        // Build title
        $title = $campaign->id
            ? $campaign->title
            : Craft::t('campaign-manager', 'New Campaign');

        // Get SMS provider and sender ID options
        $smsService = CampaignManager::$plugin->sms;
        $providerOptions = $smsService->getProviderOptions();
        $senderIdsByProvider = $smsService->getSenderIdOptionsByProvider();

        // Get current sender ID options based on campaign's provider
        $currentProviderHandle = $campaign->providerHandle ?? $settings->defaultProviderHandle;
        $senderIdOptions = $currentProviderHandle
            ? $smsService->getSenderIdOptions($currentProviderHandle)
            : [['label' => Craft::t('campaign-manager', 'Select a provider first...'), 'value' => '']];

        return $this->renderTemplate('campaign-manager/campaigns/edit', [
            'campaign' => $campaign,
            'title' => $title,
            'formOptions' => $formOptions,
            'campaignTypeOptions' => $campaignTypeOptions,
            'providerOptions' => $providerOptions,
            'senderIdOptions' => $senderIdOptions,
            'senderIdsByProvider' => $senderIdsByProvider,
        ]);
    }

    /**
     * Save a campaign
     *
     * @since 5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $campaignId = $request->getBodyParam('campaignId');
        $siteId = $request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;

        // Get or create the campaign
        if ($campaignId) {
            $campaign = Campaign::find()
                ->id($campaignId)
                ->siteId($siteId)
                ->status(null)
                ->one();

            if (!$campaign) {
                throw new NotFoundHttpException('Campaign not found');
            }

            $this->requirePermission('campaignManager:editCampaigns');
        } else {
            $this->requirePermission('campaignManager:createCampaigns');
            $campaign = new Campaign();
            $campaign->siteId = (int)$siteId;
        }

        // Set the attributes
        $campaign->title = $request->getBodyParam('title');

        // Set enabled ONLY for the current site being edited
        $enabledParam = $request->getBodyParam('enabled');
        $enabled = $enabledParam === '1' || $enabledParam === 1 || $enabledParam === true;
        $campaign->setEnabledForSite($enabled);

        $campaign->campaignType = $request->getBodyParam('campaignType');
        $campaign->formId = $request->getBodyParam('formId') ?: null;
        // Convert user-friendly duration to ISO 8601
        $campaign->invitationDelayPeriod = $this->buildDurationString(
            $request->getBodyParam('invitationDelayValue'),
            $request->getBodyParam('invitationDelayUnit')
        );
        $campaign->invitationExpiryPeriod = $this->buildDurationString(
            $request->getBodyParam('invitationExpiryValue'),
            $request->getBodyParam('invitationExpiryUnit')
        );
        $campaign->emailInvitationMessage = $request->getBodyParam('emailInvitationMessage');
        $campaign->emailInvitationSubject = $request->getBodyParam('emailInvitationSubject');
        $campaign->smsInvitationMessage = $request->getBodyParam('smsInvitationMessage');
        $campaign->providerHandle = $request->getBodyParam('providerHandle') ?: null;
        $campaign->senderId = $request->getBodyParam('senderId') ?: null;

        // Set field values
        $campaign->setFieldValuesFromRequest('fields');

        // Save the campaign (with propagation and search index update)
        if (!Craft::$app->getElements()->saveElement($campaign, true, true, true)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $campaign->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Couldn\'t save campaign.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'campaign' => $campaign,
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'id' => $campaign->id,
                'title' => $campaign->title,
                'cpEditUrl' => $campaign->getCpEditUrl(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Campaign saved.'));

        return $this->redirectToPostedUrl($campaign);
    }

    /**
     * Delete a campaign
     *
     * @since 5.0.0
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('campaignManager:deleteCampaigns');

        $campaignId = Craft::$app->getRequest()->getRequiredBodyParam('campaignId');

        $campaign = Campaign::find()
            ->id($campaignId)
            ->status(null)
            ->one();

        if (!$campaign) {
            throw new NotFoundHttpException('Campaign not found');
        }

        if (!Craft::$app->getElements()->deleteElement($campaign)) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false]);
            }

            Craft::$app->getSession()->setError(Craft::t('campaign-manager', 'Couldn\'t delete campaign.'));
            return $this->redirectToPostedUrl($campaign);
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign-manager', 'Campaign deleted.'));

        return $this->redirect('campaign-manager');
    }

    /**
     * Run all campaigns or a specific campaign (queued)
     *
     * @since 5.0.0
     */
    public function actionRunAll(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requirePermission('campaignManager:runCampaigns');

        $campaignId = Craft::$app->getRequest()->getBodyParam('campaignId');
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');

        // Get all sites if no specific site is provided
        $sites = $siteId
            ? [Craft::$app->getSites()->getSiteById((int) $siteId)]
            : Craft::$app->getSites()->getAllSites();

        $jobsQueued = 0;

        foreach ($sites as $site) {
            if (!$site) {
                continue;
            }

            // Get campaigns to run
            $campaigns = $this->getCampaignsToRun($campaignId, $site->id);

            foreach ($campaigns as $campaign) {
                // Queue a job for each campaign/site combination
                Craft::$app->getQueue()->push(new ProcessCampaignJob([
                    'campaignId' => $campaign->id,
                    'siteId' => $site->id,
                    'sendSms' => true,
                    'sendEmail' => true,
                ]));
                $jobsQueued++;

                $this->logInfo('Campaign job queued', [
                    'campaignId' => $campaign->id,
                    'siteId' => $site->id,
                ]);
            }
        }

        $this->logInfo('Campaign jobs queued', ['count' => $jobsQueued]);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'jobsQueued' => $jobsQueued,
            ]);
        }

        if ($jobsQueued > 0) {
            Craft::$app->getSession()->setNotice(
                Craft::t('campaign-manager', '{count} campaign job(s) queued. Check the queue for progress.', ['count' => $jobsQueued])
            );
        } else {
            Craft::$app->getSession()->setNotice(
                Craft::t('campaign-manager', 'No campaigns to process.')
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Get campaigns to run
     *
     * @return Campaign[]
     */
    private function getCampaignsToRun(?string $campaignId, int $siteId): array
    {
        if ($campaignId) {
            // Only run enabled campaigns
            $campaign = Campaign::find()
                ->id((int)$campaignId)
                ->siteId($siteId)
                ->status('enabled')
                ->one();

            return $campaign ? [$campaign] : [];
        }

        // Get all enabled campaigns for the site
        return Campaign::find()
            ->siteId($siteId)
            ->status('enabled')
            ->all();
    }

    /**
     * Build ISO 8601 duration string from value and unit
     */
    private function buildDurationString(?string $value, ?string $unit): ?string
    {
        if (empty($value) || (int)$value <= 0) {
            return null;
        }

        $intValue = (int)$value;

        // Hours use PT prefix, others use P prefix
        if ($unit === 'H') {
            return "PT{$intValue}H";
        }

        // Days, Weeks, Months use P prefix
        return "P{$intValue}{$unit}";
    }
}
