<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\State;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class PolicyValidationTest extends TestCase
{
    public function test_retry_counts_total_attempts_and_requires_explicit_permission(): void
    {
        $default = RetryPolicy::create();
        self::assertSame(1, $default->maxAttempts());
        self::assertFalse($default->canRetryAfter(1, OperationContext::create('write')));

        $policy = RetryPolicy::create(maxAttempts: 3);
        $safe = OperationContext::create('read', retryAllowed: true);
        $unsafe = OperationContext::create('write');
        self::assertTrue($policy->canRetryAfter(1, $safe));
        self::assertTrue($policy->canRetryAfter(2, $safe));
        self::assertFalse($policy->canRetryAfter(3, $safe));
        self::assertFalse($policy->canRetryAfter(1, $unsafe));
        self::assertTrue(RetryPolicy::create(maxAttempts: 2, allowUnsafeRetries: true)->canRetryAfter(1, $unsafe));
    }

    public function test_retry_rejects_invalid_attempt_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::create(maxAttempts: 0);
    }

    public function test_retry_rejects_nonpositive_completed_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::create()->canRetryAfter(0, OperationContext::create('read'));
    }

    public function test_circuit_policy_validates_all_boundaries(): void
    {
        foreach ([['failureThreshold' => 0], ['openDurationMilliseconds' => 0], ['halfOpenProbeLimit' => 0]] as $invalid) {
            try {
                CircuitBreakerPolicy::create(...$invalid);
                self::fail('Invalid circuit policy was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $policy = CircuitBreakerPolicy::create(failureThreshold: 2, openDurationMilliseconds: 500, halfOpenProbeLimit: 3);
        self::assertSame(2, $policy->failureThreshold());
        self::assertSame(500, $policy->openDurationMilliseconds());
        self::assertSame(3, $policy->halfOpenProbeLimit());
        self::assertSame('CLOSED', State::CLOSED->value);
        self::assertSame('OPEN', State::OPEN->value);
        self::assertSame('HALF_OPEN', State::HALF_OPEN->value);
    }

    public function test_bulkhead_defaults_to_no_queue_and_validates_limits(): void
    {
        $default = BulkheadPolicy::create();
        self::assertSame(1, $default->maxConcurrent());
        self::assertSame(0, $default->maxQueued());
        self::assertSame(4, BulkheadPolicy::create(maxConcurrent: 2, maxQueued: 4)->maxQueued());

        foreach ([['maxConcurrent' => 0], ['maxQueued' => -1]] as $invalid) {
            try {
                BulkheadPolicy::create(...$invalid);
                self::fail('Invalid bulkhead policy was accepted.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_rate_policy_validates_bounds_and_exposes_refill_per_millisecond(): void
    {
        $default = RateLimitPolicy::create();
        self::assertSame(0, $default->maxWaitMilliseconds());

        $policy = RateLimitPolicy::create(capacity: 10, refillPerSecond: 2.5, maxWaitMilliseconds: 200);
        self::assertSame(10, $policy->capacity());
        self::assertSame(2.5, $policy->refillPerSecond());
        self::assertSame(0.0025, $policy->refillPerMillisecond());
        self::assertSame(200, $policy->maxWaitMilliseconds());

        foreach ([['capacity' => 0], ['refillPerSecond' => 0.0], ['refillPerSecond' => -1.0], ['refillPerSecond' => INF], ['refillPerSecond' => NAN], ['maxWaitMilliseconds' => -1]] as $invalid) {
            try {
                RateLimitPolicy::create(...$invalid);
                self::fail('Invalid rate policy was accepted.');
            } catch (InvalidArgumentException) {
            }
        }
    }
}
