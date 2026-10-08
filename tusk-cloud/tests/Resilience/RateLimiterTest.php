<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\Deadline;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
use Tusk\Cloud\Resilience\RateLimiter;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class RateLimiterTest extends TestCase
{
    public function test_bucket_starts_full_consumes_one_token_per_call_and_rejects_when_empty(): void
    {
        $limiter = new RateLimiter(new FakeClock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 2);
        $limiter->acquire($context, $policy);
        $limiter->acquire($context, $policy);

        $this->expectException(RateLimitRejectedException::class);
        $limiter->acquire($context, $policy);
    }

    public function test_fractional_refill_is_deterministic_and_zero_elapsed_adds_nothing(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 1, refillPerSecond: 2.5);
        $limiter->acquire($context, $policy);
        $clock->sleepMilliseconds(399);
        $this->assertRejected($limiter, $context, $policy);
        $clock->sleepMilliseconds(1);
        $limiter->acquire($context, $policy);
        $this->assertRejected($limiter, $context, $policy);
    }

    public function test_refill_never_exceeds_capacity(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 2, refillPerSecond: 1);
        $limiter->acquire($context, $policy);
        $clock->sleepMilliseconds(10_000);
        $limiter->acquire($context, $policy);
        $limiter->acquire($context, $policy);
        $this->assertRejected($limiter, $context, $policy);
    }

    public function test_no_wait_policy_rejects_without_advancing_clock(): void
    {
        $clock = new FakeClock(100);
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 1, maxWaitMilliseconds: 0);
        $limiter->acquire($context, $policy);
        $this->assertRejected($limiter, $context, $policy);
        self::assertSame(100, $clock->nowMilliseconds());
    }

    public function test_waits_only_until_next_token_within_policy_limit(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 1, refillPerSecond: 2.5, maxWaitMilliseconds: 500);
        $limiter->acquire($context, $policy);
        $limiter->acquire($context, $policy);
        self::assertSame(400, $clock->nowMilliseconds());
    }

    public function test_wait_is_capped_by_policy_maximum(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 1, refillPerSecond: 1, maxWaitMilliseconds: 250);
        $limiter->acquire($context, $policy);
        $this->assertRejected($limiter, $context, $policy);
        self::assertSame(250, $clock->nowMilliseconds());
    }

    public function test_wait_is_capped_by_operation_deadline(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $policy = RateLimitPolicy::create(capacity: 1, refillPerSecond: 1, maxWaitMilliseconds: 500);
        $limiter->acquire(OperationContext::create('read'), $policy);
        $context = OperationContext::create('read', new Deadline(125));
        $this->assertRejected($limiter, $context, $policy);
        self::assertSame(125, $clock->nowMilliseconds());
    }

    public function test_limiter_instance_cannot_mix_bucket_policies(): void
    {
        $limiter = new RateLimiter(new FakeClock);
        $limiter->acquire(OperationContext::create('first'), RateLimitPolicy::create(capacity: 1, refillPerSecond: 2));

        $this->expectException(InvalidArgumentException::class);
        $limiter->acquire(OperationContext::create('second'), RateLimitPolicy::create(capacity: 2, refillPerSecond: 2));
    }

    public function test_tiny_positive_refill_rate_respects_bounded_wait_without_division_error(): void
    {
        $clock = new FakeClock;
        $limiter = new RateLimiter($clock);
        $context = OperationContext::create('read');
        $policy = RateLimitPolicy::create(capacity: 1, refillPerSecond: 1.0e-320, maxWaitMilliseconds: 100);
        $limiter->acquire($context, $policy);

        $this->assertRejected($limiter, $context, $policy);
        self::assertSame(100, $clock->nowMilliseconds());
    }

    private function assertRejected(RateLimiter $limiter, OperationContext $context, RateLimitPolicy $policy): void
    {
        $rejected = false;
        try {
            $limiter->acquire($context, $policy);
        } catch (RateLimitRejectedException) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'Empty bucket admitted an operation.');
    }
}
