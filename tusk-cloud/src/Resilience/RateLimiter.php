<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
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

        if ($this->tokens >= 1.0) {
            $this->tokens--;

            return;
        }

        $deadline = $context->deadline();
        $waited = 0;
        while (true) {
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

            $this->clock->sleepMilliseconds($sleep);
            $afterSleep = $this->clock->nowMilliseconds();
            if ($afterSleep <= $now) {
                throw new RateLimitRejectedException('Clock did not advance during rate limit wait.');
            }

            $waited += min($afterSleep - $now, $policyRemaining);
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
