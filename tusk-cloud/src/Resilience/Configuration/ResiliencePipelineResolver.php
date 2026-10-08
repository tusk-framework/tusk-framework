<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Configuration;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\Backoff\DecorrelatedJitterBackoff;
use Tusk\Cloud\Resilience\Backoff\ExponentialBackoff;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Random\SecureRandomSource;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipelineBuilder;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Contracts\Cloud\Resilience\RandomSourceInterface;

final class ResiliencePipelineResolver
{
    public static function resolve(
        string $name,
        ResilienceConfiguration $configuration,
        ResiliencePipelineFactory $factory,
        ?RandomSourceInterface $randomSource = null,
    ): ResiliencePipelineBuilder {
        $policy = $configuration->policy($name);
        if ($policy === null) {
            throw new InvalidArgumentException(sprintf('No resilience policy is configured at resilience.policies.%s.', trim($name)));
        }

        $builder = $factory->pipeline($policy->name());
        if ($retry = $policy->retry()) {
            $backoff = $retry['backoff'] ?? ['type' => 'fixed'];
            $baseDelay = $backoff['base_delay_ms'] ?? null;
            $maxDelay = $backoff['max_delay_ms'] ?? null;
            $strategy = match ($backoff['type']) {
                'fixed' => FixedBackoff::create($baseDelay ?? 0, $maxDelay ?? PHP_INT_MAX),
                'exponential' => ExponentialBackoff::create($baseDelay ?? 100, $maxDelay ?? 30_000),
                'decorrelated_jitter' => DecorrelatedJitterBackoff::create(
                    $randomSource ?? new SecureRandomSource,
                    $baseDelay ?? 100,
                    $maxDelay ?? 30_000,
                ),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported backoff type at resilience.policies.%s.retry.backoff.type.',
                    $policy->name(),
                )),
            };
            $retryOn = $retry['retry_on'] ?? [];
            $doNotRetryOn = $retry['do_not_retry_on'] ?? [];
            $classifier = $retryOn !== [] || $doNotRetryOn !== []
                ? new ConfiguredFailureClassifier($retryOn, $doNotRetryOn)
                : null;

            $builder = $builder->withRetry(RetryPolicy::create(
                $retry['max_attempts'] ?? 1,
                $strategy,
                $classifier,
                $retry['allow_unsafe_retries'] ?? false,
            ));
        }

        if ($circuitBreaker = $policy->circuitBreaker()) {
            $builder = $builder->withCircuitBreaker(CircuitBreakerPolicy::create(
                $circuitBreaker['failure_threshold'] ?? 5,
                $circuitBreaker['open_duration_ms'] ?? 10_000,
                $circuitBreaker['half_open_probe_limit'] ?? 1,
            ));
        }
        if ($bulkhead = $policy->bulkhead()) {
            $builder = $builder->withBulkhead(BulkheadPolicy::create(
                $bulkhead['max_concurrent'] ?? 1,
                $bulkhead['max_queued'] ?? 0,
            ));
        }
        if ($rateLimit = $policy->rateLimit()) {
            $builder = $builder->withRateLimit(RateLimitPolicy::create(
                $rateLimit['capacity'] ?? 1,
                $rateLimit['refill_per_second'] ?? 1.0,
                $rateLimit['max_wait_ms'] ?? 0,
            ));
        }

        return $builder;
    }
}
