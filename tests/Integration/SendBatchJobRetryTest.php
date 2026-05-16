<?php
/**
 * LindemannRock Campaign Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Integration;

use lindemannrock\campaignmanager\exceptions\SendBatchFailedException;
use lindemannrock\campaignmanager\jobs\SendBatchJob;
use lindemannrock\campaignmanager\tests\TestCase;
use RuntimeException;

/**
 * Pins {@see SendBatchJob::canRetry()} for the no-success branch. When a batch
 * finishes with zero successful sends and at least one error, `execute()`
 * throws {@see SendBatchFailedException} so the operator sees the failure in
 * the queue UI. Auto-retry for that case would just re-fire the same broken
 * email template / provider config three times — `canRetry()` must short-
 * circuit. Generic exceptions still retry up to attempt 3 so transient
 * network failures recover.
 *
 * @since 5.11.0
 */
final class SendBatchJobRetryTest extends TestCase
{
    public function testCanRetryReturnsFalseForSendBatchFailedException(): void
    {
        $job = new SendBatchJob();
        $error = new SendBatchFailedException('all sends failed');

        self::assertFalse($job->canRetry(1, $error));
        self::assertFalse($job->canRetry(2, $error));
        self::assertFalse($job->canRetry(3, $error));
    }

    public function testCanRetryRespectsAttemptLimitForGenericException(): void
    {
        $job = new SendBatchJob();
        $error = new RuntimeException('transient network error');

        self::assertTrue($job->canRetry(1, $error));
        self::assertTrue($job->canRetry(2, $error));
        self::assertFalse($job->canRetry(3, $error));
    }
}
