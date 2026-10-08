<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

/** A worker-local token bucket configured by the first policy it receives. */
final class RateLimiter
{
    private ?string $configuredPolicyKey = null;

    private float $tokens = 0.0;

    private ?int $updatedAt = null;

    public function __construct(private readonly ClockInterface $clock) {}

    public function acquire(OperationContext $context, RateLimitPolicy $policy): void
    {
        $this->assertAdmissible($context);
        $key = $this->policyKey($policy);
        if ($this->configuredPolicyKey !== null && $this->configuredPolicyKey !== $key) {
            throw new InvalidArgumentException('A rate limiter instance cannot be used with multiple policies.');
        }
        $this->configuredPolicyKey ??= $key;

        $start = $this->clock->nowMilliseconds();
        if ($this->updatedAt === null) {
            $this->tokens = (float) $policy->capacity();
            $this->updatedAt = $start;
        }
        $this->refill($start, $policy);

        $this->assertAdmissible($context);
        if ($this->tokens >= 1.0) {
            $this->tokens--;

            return;
        }

        $deadline = $context->deadline();
        $waited = 0;
        while (true) {
            $this->assertAdmissible($context);
            $now = $this->clock->nowMilliseconds();
            $this->refill($now, $policy);

            if ($this->tokens >= 1.0) {
                $this->tokens--;

                return;
            }

            $policyRemaining = $policy->maxWaitMilliseconds() - $waited;
            $deadlineRemaining = $deadline?->remainingMilliseconds($now);
            if ($policyRemaining <= 0 || $deadlineRemaining === 0) {
                throw new RateLimitRejectedException('Rate limit wait budget expired.');
            }

            $tokensNeeded = 1.0 - $this->tokens;
            $tokensPerMillisecond = $policy->refillPerMillisecond();
            $requiredMilliseconds = $tokensPerMillisecond > 0 ? $tokensNeeded / $tokensPerMillisecond : INF;
            $neededMilliseconds = ! is_finite($requiredMilliseconds) || $requiredMilliseconds >= PHP_INT_MAX
                ? PHP_INT_MAX
                : max(1, (int) ceil($requiredMilliseconds));
            $sleep = min($neededMilliseconds, $policyRemaining, $deadlineRemaining ?? PHP_INT_MAX);
            if ($sleep <= 0) {
                throw new RateLimitRejectedException('Rate limit wait budget expired.');
            }

            $this->sleepWithCancellation($sleep, $context);
            $afterSleep = $this->clock->nowMilliseconds();
            if ($afterSleep <= $now) {
                throw new RateLimitRejectedException('Clock did not advance during rate limit wait.');
            }

            $elapsed = $afterSleep - $now;
            $waited = $elapsed > PHP_INT_MAX - $waited ? PHP_INT_MAX : $waited + $elapsed;
            if ($waited > $policy->maxWaitMilliseconds()) {
                throw new RateLimitRejectedException('Rate limit wait budget expired.');
            }
        }
    }

    private function assertAdmissible(OperationContext $context): void
    {
        if ($context->isCancellationRequested()) {
            throw new OperationCancelledException($context->operation());
        }

        $deadline = $context->deadline();
        if ($deadline !== null && $deadline->isExpired($this->clock->nowMilliseconds())) {
            throw new ResilienceDeadlineExceededException($context->operation());
        }
    }

    private function sleepWithCancellation(int $milliseconds, OperationContext $context): void
    {
        $remaining = $milliseconds;
        while ($remaining > 0) {
            $this->assertAdmissible($context);
            $slice = min(10, $remaining);
            $this->clock->sleepMilliseconds($slice);
            $remaining -= $slice;
        }
    }

    private function refill(int $now, RateLimitPolicy $policy): void
    {
        if ($this->updatedAt === null || $now < $this->updatedAt) {
            throw new RateLimitRejectedException('Monotonic clock moved backwards.');
        }

        $elapsed = $now - $this->updatedAt;
        if ($elapsed > 0) {
            $missing = $policy->capacity() - $this->tokens;
            $tokensPerMillisecond = $policy->refillPerMillisecond();
            if ($tokensPerMillisecond === 0.0) {
                $this->updatedAt = $now;

                return;
            }

            $this->tokens = $elapsed >= $missing / $tokensPerMillisecond
                ? (float) $policy->capacity()
                : min((float) $policy->capacity(), $this->tokens + $elapsed * $tokensPerMillisecond);
            $this->updatedAt = $now;
        }
    }

    private function policyKey(RateLimitPolicy $policy): string
    {
        return $policy->capacity().':'.sprintf('%.17g', $policy->refillPerSecond());
    }
}
