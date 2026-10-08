<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Closure;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class ResiliencePipelineBuilder
{
    private function __construct(
        private string $name,
        private RetryExecutor $retryExecutor,
        private CircuitBreaker $circuitBreaker,
        private Bulkhead $bulkhead,
        private RateLimiter $rateLimiter,
        private ?RetryPolicy $retryPolicy = null,
        private ?CircuitBreakerPolicy $circuitBreakerPolicy = null,
        private ?BulkheadPolicy $bulkheadPolicy = null,
        private ?RateLimitPolicy $rateLimitPolicy = null,
        private ?Closure $fallback = null,
        private ?ResilienceInstrumentation $instrumentation = null,
    ) {}

    public static function create(
        string $name,
        RetryExecutor $retryExecutor,
        CircuitBreaker $circuitBreaker,
        Bulkhead $bulkhead,
        RateLimiter $rateLimiter,
        ?ResilienceInstrumentation $instrumentation = null,
    ): self {
        $context = OperationContext::create($name);

        return new self($context->operation(), $retryExecutor, $circuitBreaker, $bulkhead, $rateLimiter, instrumentation: $instrumentation);
    }

    public function withRetry(RetryPolicy $policy): self
    {
        return $this->copy(retryPolicy: $policy, replaceRetry: true);
    }

    public function withCircuitBreaker(CircuitBreakerPolicy $policy): self
    {
        return $this->copy(circuitBreakerPolicy: $policy, replaceCircuit: true);
    }

    public function withBulkhead(BulkheadPolicy $policy): self
    {
        return $this->copy(bulkheadPolicy: $policy, replaceBulkhead: true);
    }

    public function withRateLimit(RateLimitPolicy $policy): self
    {
        return $this->copy(rateLimitPolicy: $policy, replaceRateLimit: true);
    }

    public function withFallback(callable $fallback): self
    {
        return $this->copy(fallback: Closure::fromCallable($fallback), replaceFallback: true);
    }

    public function build(): ResiliencePipeline
    {
        return new ResiliencePipeline(
            $this->name,
            $this->retryExecutor,
            $this->circuitBreaker,
            $this->bulkhead,
            $this->rateLimiter,
            $this->retryPolicy,
            $this->circuitBreakerPolicy,
            $this->bulkheadPolicy,
            $this->rateLimitPolicy,
            $this->fallback,
            $this->instrumentation,
        );
    }

    public function run(callable $operation, ?OperationContext $context = null): mixed
    {
        return $this->build()->run($operation, $context);
    }

    private function copy(
        ?RetryPolicy $retryPolicy = null,
        bool $replaceRetry = false,
        ?CircuitBreakerPolicy $circuitBreakerPolicy = null,
        bool $replaceCircuit = false,
        ?BulkheadPolicy $bulkheadPolicy = null,
        bool $replaceBulkhead = false,
        ?RateLimitPolicy $rateLimitPolicy = null,
        bool $replaceRateLimit = false,
        ?Closure $fallback = null,
        bool $replaceFallback = false,
    ): self {
        return new self(
            $this->name,
            $this->retryExecutor,
            $this->circuitBreaker,
            $this->bulkhead,
            $this->rateLimiter,
            $replaceRetry ? $retryPolicy : $this->retryPolicy,
            $replaceCircuit ? $circuitBreakerPolicy : $this->circuitBreakerPolicy,
            $replaceBulkhead ? $bulkheadPolicy : $this->bulkheadPolicy,
            $replaceRateLimit ? $rateLimitPolicy : $this->rateLimitPolicy,
            $replaceFallback ? $fallback : $this->fallback,
            $this->instrumentation,
        );
    }
}
