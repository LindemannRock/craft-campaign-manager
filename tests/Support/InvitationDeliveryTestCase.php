<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Support;

use Craft;
use craft\web\View;
use DateTime;
use lindemannrock\campaignmanager\elements\Campaign;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\records\ActivityLogRecord;
use lindemannrock\campaignmanager\records\RecipientRecord;
use lindemannrock\campaignmanager\tests\Stubs\ScriptedMailer;
use lindemannrock\campaignmanager\tests\Stubs\ScriptedSmsManagerSmsService;
use lindemannrock\campaignmanager\tests\Stubs\UnshortenedLinkRecipientsService;
use lindemannrock\smsmanager\records\SenderIdRecord;
use RuntimeException;
use Twig\Cache\CacheInterface;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use yii\mutex\DbMutex;

/**
 * Base for tests that run invitation batches through Campaign Manager's real
 * send services while nothing leaves the process.
 *
 * SMS are captured where Campaign Manager hands them to SMS Manager, and
 * emails where the email service hands them to the application mailer, so
 * the send-date writes that follow a successful send are the real ones.
 * Link shortening is switched off so no shortener is contacted.
 *
 * Besides what {@see SiteRestrictedUserTestCase} owns, every test owns its
 * SMS provider and sender, the mailer it installed, the sender address and
 * email layout it pinned, and every lock holder it opened; all of them are
 * put back or closed during teardown, and a delivery lock still held by then
 * fails the test.
 *
 * The email layout is a fixture of this package, and Craft compiles every
 * template it renders into the project's shared compiled-template
 * directory. So that nothing is left there, the site Twig environment keeps
 * its compiled templates in memory for the duration of a test, its cache
 * setting is put back afterwards, and a compiled file for the fixture found
 * at either end is removed and, after the test, reported.
 *
 * @since 5.16.0
 */
abstract class InvitationDeliveryTestCase extends SiteRestrictedUserTestCase
{
    use SmsManagerResourceOwnership;

    protected const SMS = 'sms';

    protected const EMAIL = 'email';

    private const SENDER_ADDRESS_VARIABLE = 'SYSTEM_EMAIL';

    /**
     * The site template the email service renders every invitation with.
     */
    private const EMAIL_LAYOUT_TEMPLATE = '_emails/craft/index';

    protected ScriptedSmsManagerSmsService $smsProvider;

    protected ScriptedMailer $mailer;

    /**
     * The site every recipient of the test belongs to.
     */
    protected int $siteId;

    private ?SenderIdRecord $sender = null;

    private ?object $originalMailer = null;

    private ?string $originalTemplatesPath = null;

    /**
     * The Twig environment that renders the email layout fixture.
     */
    private ?Environment $siteTwig = null;

    private CacheInterface|string|false|null $originalTwigCache = null;

    private ?string $originalTwigCacheImplementation = null;

    /**
     * Where the environment would compile the fixture to, if it compiled to
     * files.
     */
    private ?string $fixtureCompiledPath = null;

    private bool $senderAddressPinned = false;

    private bool $hadSenderAddress = false;

    private mixed $originalSenderAddress = null;

    /**
     * @var list<SeparateConnectionMutex>
     */
    private array $lockHolders = [];

    /**
     * @var list<int>
     */
    private array $deliveryRecipientIds = [];

    private int $phoneNumbers = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Overlap protection is only proven against locks other processes
        // contend for, so a driver that keeps locks in memory fails here
        // instead of letting the tests pass without meaning anything.
        self::assertInstanceOf(
            DbMutex::class,
            SeparateConnectionMutex::applicationLockDriver(),
            'These tests need the application mutex to be backed by the database.',
        );

        [$this->siteId] = $this->siteIds(1);
        $this->sender = $this->seedSmsSender($this->seedSmsProvider(['KW']));

        $this->smsProvider = new ScriptedSmsManagerSmsService();
        $this->swapPluginComponent('sms-manager', 'sms', $this->smsProvider);
        $this->swapPluginComponent('campaign-manager', 'recipients', new UnshortenedLinkRecipientsService());

        $this->originalMailer = Craft::$app->getMailer();
        $this->mailer = new ScriptedMailer();
        Craft::$app->set('mailer', $this->mailer);

        // The email service takes its sender address from the environment
        // and its layout from the project's templates; both are pinned so
        // an invitation can be built in any project the suite runs in.
        $this->hadSenderAddress = array_key_exists(self::SENDER_ADDRESS_VARIABLE, $_SERVER);
        $this->originalSenderAddress = $_SERVER[self::SENDER_ADDRESS_VARIABLE] ?? null;
        $_SERVER[self::SENDER_ADDRESS_VARIABLE] = 'campaign-manager-test@example.test';
        $this->senderAddressPinned = true;

        $templatesPath = Craft::getAlias('@templates');
        if (!is_string($templatesPath)) {
            throw new RuntimeException('The project has no templates path to put back after the test.');
        }
        $this->originalTemplatesPath = $templatesPath;
        Craft::setAlias('@templates', dirname(__DIR__) . '/Fixtures/Templates');

        // Rendering the fixture must not leave a compiled template in the
        // project's cache directory: the environment keeps templates in
        // memory for this test, and an entry an earlier run compiled for
        // the fixture is removed by its exact key first.
        $this->siteTwig = $this->siteTwigEnvironment();
        $this->originalTwigCache = $this->siteTwig->getCache();
        $this->originalTwigCacheImplementation = $this->cacheImplementation($this->siteTwig);
        $this->fixtureCompiledPath = $this->compiledFixturePath($this->siteTwig);
        if ($this->fixtureCompiledPath !== null && is_file($this->fixtureCompiledPath)) {
            unlink($this->fixtureCompiledPath);
        }
        $this->siteTwig->setCache(false);
    }

    protected function cleanupExternalState(): void
    {
        $failure = null;

        foreach ([
            $this->closeLockHolders(...),
            $this->releaseSurvivingDeliveryLocks(...),
            $this->restoreTwigCache(...),
            $this->assertCompiledTemplateCacheIsAsFound(...),
            $this->restoreDeliveryEnvironment(...),
            parent::cleanupExternalState(...),
            $this->cleanupSmsManagerResources(...),
        ] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Save a campaign that has an SMS and an email invitation and sends SMS
     * through the test's own sender.
     */
    protected function seedDeliverableCampaign(): Campaign
    {
        if ($this->sender === null) {
            throw new RuntimeException('The test SMS sender was not created.');
        }

        $campaign = $this->seedOwnedCampaign();
        $campaign->senderHandle = (string)$this->sender->handle;
        $campaign->smsInvitationMessage = 'Please take part.';
        $campaign->emailInvitationSubject = 'Invitation';
        $campaign->emailInvitationMessage = 'Please take part.';
        $this->saveTestElement($campaign, false, true, false);

        return $campaign;
    }

    /**
     * Save a recipient who has received nothing yet and can be reached by
     * SMS and by email.
     */
    protected function seedPendingRecipient(Campaign $campaign): RecipientRecord
    {
        $this->phoneNumbers++;
        $recipient = $this->seedOwnedRecipient($campaign, $this->siteId, [
            'sms' => sprintf('+965971766%02d', $this->phoneNumbers),
        ]);
        $this->deliveryRecipientIds[] = (int)$recipient->id;

        return $recipient;
    }

    /**
     * A batch for the recipients on both channels, as a worker receives it.
     *
     * @param list<RecipientRecord> $recipients
     * @param array<string, mixed> $settings
     */
    protected function batch(Campaign $campaign, array $recipients, array $settings = []): SendBatchJob
    {
        return new SendBatchJob(array_merge([
            'campaignId' => (int)$campaign->id,
            'siteId' => $this->siteId,
            'recipientIds' => array_map(static fn(RecipientRecord $recipient): int => (int)$recipient->id, $recipients),
            'sendSms' => true,
            'sendEmail' => true,
        ], $settings));
    }

    /**
     * A batch for the recipients on one channel only.
     *
     * @param list<RecipientRecord> $recipients
     */
    protected function channelBatch(Campaign $campaign, array $recipients, string $channel): SendBatchJob
    {
        return $this->batch($campaign, $recipients, [
            'sendSms' => $channel === self::SMS,
            'sendEmail' => $channel === self::EMAIL,
        ]);
    }

    protected function runBatch(SendBatchJob $batch): void
    {
        $batch->execute(Craft::$app->getQueue());
    }

    /**
     * Run other work while the channel's first message is on its way,
     * before the send answers and the send date is written.
     */
    protected function duringTheFirstSend(string $channel, callable $work): void
    {
        $hook = static function(int $message) use ($work): void {
            if ($message === 1) {
                $work();
            }
        };

        if ($channel === self::SMS) {
            $this->smsProvider->duringSend = $hook;
        } else {
            $this->mailer->duringSend = $hook;
        }
    }

    /**
     * Script the answers the channel gives to its next messages.
     *
     * @param list<bool|\Throwable> $answers
     */
    protected function answerSendsWith(string $channel, array $answers): void
    {
        if ($channel === self::SMS) {
            $this->smsProvider->answers = $answers;
        } else {
            $this->mailer->answers = $answers;
        }
    }

    /**
     * Where the channel's messages were handed over to, in order: phone
     * numbers for SMS, addresses for email.
     *
     * @return list<string>
     */
    protected function sentTo(string $channel): array
    {
        return $channel === self::SMS ? $this->smsProvider->sentTo() : $this->mailer->sentTo();
    }

    /**
     * Where the recipient is reached on the channel.
     */
    protected function contact(RecipientRecord $recipient, string $channel): string
    {
        return (string)($channel === self::SMS ? $recipient->sms : $recipient->email);
    }

    /**
     * The recipient's persisted send date on the channel.
     */
    protected function sendDate(RecipientRecord $recipient, string $channel): ?DateTime
    {
        $reloaded = $this->reloadRecipient($recipient);

        return $channel === self::SMS ? $reloaded->smsSendDate : $reloaded->emailSendDate;
    }

    /**
     * The activity logs the campaign's batches wrote, oldest first.
     *
     * @return list<ActivityLogRecord>
     */
    protected function batchLogs(Campaign $campaign): array
    {
        /** @var list<ActivityLogRecord> $logs */
        $logs = ActivityLogRecord::find()
            ->where(['campaignId' => $campaign->id, 'action' => 'invitations_sent_batch'])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $logs;
    }

    /**
     * What each of the campaign's batches counted, oldest first.
     *
     * @return list<array{smsSent: int, emailSent: int, errors: int}>
     */
    protected function batchCounts(Campaign $campaign): array
    {
        $counts = [];
        foreach ($this->batchLogs($campaign) as $log) {
            $details = $this->logDetails($log);
            $counts[] = [
                'smsSent' => (int)$details['smsSent'],
                'emailSent' => (int)$details['emailSent'],
                'errors' => (int)$details['errors'],
            ];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    protected function logDetails(ActivityLogRecord $log): array
    {
        $details = json_decode((string)$log->details, true);
        if (!is_array($details)) {
            throw new RuntimeException("Activity log {$log->id} has no readable details.");
        }

        return $details;
    }

    /**
     * What a batch counts when the channel sent that many invitations and
     * the batch had that many errors.
     *
     * @return array{smsSent: int, emailSent: int, errors: int}
     */
    protected function counts(string $channel, int $sent, int $errors = 0): array
    {
        return [
            'smsSent' => $channel === self::SMS ? $sent : 0,
            'emailSent' => $channel === self::EMAIL ? $sent : 0,
            'errors' => $errors,
        ];
    }

    /**
     * The lock a batch holds while it sends the recipient's invitation on
     * the channel.
     */
    protected function deliveryLockName(RecipientRecord $recipient, string $channel): string
    {
        return sprintf('campaign-manager:invitation-send:%d:%s', $recipient->id, $channel);
    }

    /**
     * Another queue worker, holding its locks on a database connection of
     * its own. It is closed during teardown.
     */
    protected function anotherWorker(): SeparateConnectionMutex
    {
        $worker = SeparateConnectionMutex::contendingWithTheApplication();
        $this->lockHolders[] = $worker;

        return $worker;
    }

    /**
     * Assert that nothing holds the recipient's delivery lock: neither this
     * process nor any other database connection.
     */
    protected function assertDeliveryLockIsFree(RecipientRecord $recipient, string $channel): void
    {
        $name = $this->deliveryLockName($recipient, $channel);

        self::assertFalse(Craft::$app->getMutex()->isAcquired($name), "This process still holds {$name}.");

        $worker = $this->anotherWorker();
        try {
            self::assertTrue($worker->acquire($name), "The database still holds {$name}.");
        } finally {
            $worker->close();
        }
    }

    private function closeLockHolders(): void
    {
        $failure = null;

        foreach ($this->lockHolders as $holder) {
            try {
                $holder->close();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        $this->lockHolders = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * A delivery lock this process still holds would starve every later
     * batch for that recipient, so it is released and reported.
     */
    private function releaseSurvivingDeliveryLocks(): void
    {
        $mutex = Craft::$app->getMutex();
        $surviving = [];

        foreach ($this->deliveryRecipientIds as $recipientId) {
            foreach ([self::SMS, self::EMAIL] as $channel) {
                $name = sprintf('campaign-manager:invitation-send:%d:%s', $recipientId, $channel);
                if ($mutex->isAcquired($name)) {
                    $mutex->release($name);
                    $surviving[] = $name;
                }
            }
        }
        $this->deliveryRecipientIds = [];

        if ($surviving !== []) {
            throw new RuntimeException('Delivery locks were still held after the test: ' . implode(', ', $surviving));
        }
    }

    private function restoreTwigCache(): void
    {
        if ($this->siteTwig !== null && $this->originalTwigCache !== null) {
            $this->siteTwig->setCache($this->originalTwigCache);
        }
    }

    /**
     * Prove the compiled-template cache is as the test found it: the
     * environment that rendered the fixture is still the one whose cache was
     * switched off, its cache setting and implementation are back, the
     * fixture would compile to the same place, and nothing is compiled there.
     * A compiled fixture found anyway is removed and reported.
     */
    private function assertCompiledTemplateCacheIsAsFound(): void
    {
        $twig = $this->siteTwig;
        $originalCache = $this->originalTwigCache;
        $originalImplementation = $this->originalTwigCacheImplementation;
        $compiledPath = $this->fixtureCompiledPath;
        $this->siteTwig = null;
        $this->originalTwigCache = null;
        $this->originalTwigCacheImplementation = null;
        $this->fixtureCompiledPath = null;

        if ($twig === null || $originalCache === null) {
            return;
        }

        // An environment Craft put in place of the pinned one compiles to
        // files under its own key, so that place is checked too.
        $current = $this->siteTwigEnvironment();
        $compiled = [];
        foreach (array_unique(array_filter([
            $compiledPath,
            $this->compiledFixturePath($twig),
            $current === $twig ? null : $this->compiledFixturePath($current),
        ])) as $path) {
            if (is_file($path)) {
                unlink($path);
                $compiled[] = $path;
            }
        }

        self::assertSame(
            $twig,
            $current,
            'The site Twig environment was replaced during the test, so its compiled-template cache was not under the test’s control.',
        );
        self::assertSame($originalCache, $twig->getCache(), 'The Twig cache setting was not put back.');
        self::assertSame($originalImplementation, $this->cacheImplementation($twig), 'The Twig cache implementation was not put back.');
        self::assertSame($compiledPath, $this->compiledFixturePath($twig), 'The fixture would now compile somewhere else than before the test.');
        self::assertSame([], $compiled, 'The test compiled its email fixture into the project cache; the files were removed.');
    }

    /**
     * The environment that renders site templates, obtained the way the
     * renderer obtains it: with site template mode active, since Craft
     * composes a new environment from the mode in force at that moment.
     */
    private function siteTwigEnvironment(): Environment
    {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        try {
            return $view->getTwig();
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    private function cacheImplementation(Environment $twig): string
    {
        $cache = $twig->getCache(false);

        return is_object($cache) ? get_class($cache) : gettype($cache);
    }

    /**
     * Where the environment compiles the email layout fixture to, or null
     * when it does not compile to files. Derived the way the renderer does:
     * from the fixture's resolved path and the environment's options.
     */
    private function compiledFixturePath(Environment $twig): ?string
    {
        $cache = $twig->getCache(false);
        if (!$cache instanceof FilesystemCache) {
            return null;
        }

        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        try {
            $resolved = $view->resolveTemplate(self::EMAIL_LAYOUT_TEMPLATE);
            $fixture = dirname(__DIR__) . '/Fixtures/Templates/' . self::EMAIL_LAYOUT_TEMPLATE . '.twig';
            if (!is_string($resolved) || realpath($resolved) !== realpath($fixture)) {
                throw new RuntimeException(sprintf(
                    'The email layout resolves to %s instead of the test fixture.',
                    var_export($resolved, true),
                ));
            }

            return $cache->generateKey(self::EMAIL_LAYOUT_TEMPLATE, $twig->getTemplateClass(self::EMAIL_LAYOUT_TEMPLATE));
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    private function restoreDeliveryEnvironment(): void
    {
        if ($this->originalMailer !== null) {
            Craft::$app->set('mailer', $this->originalMailer);
            $this->originalMailer = null;
        }

        if ($this->originalTemplatesPath !== null) {
            Craft::setAlias('@templates', $this->originalTemplatesPath);
            $this->originalTemplatesPath = null;
        }

        if ($this->senderAddressPinned) {
            if ($this->hadSenderAddress) {
                $_SERVER[self::SENDER_ADDRESS_VARIABLE] = $this->originalSenderAddress;
            } else {
                unset($_SERVER[self::SENDER_ADDRESS_VARIABLE]);
            }
            $this->senderAddressPinned = false;
            $this->originalSenderAddress = null;
        }
    }
}
