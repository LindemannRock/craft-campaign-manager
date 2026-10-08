<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use Closure;
use craft\mail\Mailer;
use Throwable;
use yii\mail\MessageInterface;

/**
 * Application mailer that records every message handed to it instead of
 * sending it, and then answers the way the test scripted: it can run other
 * work while the message is "in transit", report a failed send, or throw.
 *
 * Campaign Manager's email service runs unchanged around it, so a
 * successful answer is followed by the real send-date write.
 *
 * @since 5.16.0
 */
final class ScriptedMailer extends Mailer
{
    /**
     * The addresses of each message handed over, in order.
     *
     * @var list<list<string>>
     */
    public array $sentMessages = [];

    /**
     * Runs while a message is in transit, before the transport answers.
     * Receives the number of the message, counted from 1.
     *
     * @var (Closure(int): void)|null
     */
    public ?Closure $duringSend = null;

    /**
     * The transport's answers to successive messages; a message past the
     * end of the list is accepted.
     *
     * @var list<bool|Throwable>
     */
    public array $answers = [];

    /**
     * @param MessageInterface $message
     */
    public function send($message): bool
    {
        $addresses = [];
        foreach ((array)$message->getTo() as $address => $name) {
            $addresses[] = is_string($address) ? $address : (string)$name;
        }
        $this->sentMessages[] = $addresses;
        $answer = array_shift($this->answers) ?? true;

        if ($this->duringSend !== null) {
            ($this->duringSend)(count($this->sentMessages));
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * Every address a message was handed over for, in order.
     *
     * @return list<string>
     */
    public function sentTo(): array
    {
        return array_merge(...$this->sentMessages);
    }
}
