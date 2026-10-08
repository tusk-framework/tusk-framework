<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Closure;
use Throwable;
use Tusk\Cloud\Resilience\Exception\ResilienceFallbackException;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class ResiliencePipeline
{
    public function __construct(
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
    ) {}

    public function run(callable $operation, ?OperationContext $context = null): mixed
    {
        $context ??= OperationContext::create($this->name);

        try {
            $retryPolicy = $this->retryPolicy ?? RetryPolicy::create();
            $attempts = function (OperationContext $operationContext) use ($operation, $retryPolicy): mixed {
                return $this->retryExecutor->execute(function (OperationContext $attemptContext) use ($operation): mixed {
                    if ($this->rateLimitPolicy !== null) {
                        $this->rateLimiter->acquire($attemptContext, $this->rateLimitPolicy);
                    }

                    return $operation($attemptContext);
                }, $operationContext, $retryPolicy);
            };

            $protected = function (OperationContext $operationContext) use ($attempts, $retryPolicy): mixed {
                if ($this->circuitBreakerPolicy === null) {
                    return $attempts($operationContext);
                }

                return $this->circuitBreaker->execute($attempts, $operationContext, $this->circuitBreakerPolicy, $retryPolicy->classifier());
            };

            if ($this->bulkheadPolicy !== null) {
                return $this->bulkhead->run($protected, $context, $this->bulkheadPolicy);
            }

            return $protected($context);
        } catch (Throwable $failure) {
            if ($this->fallback === null) {
                throw $failure;
            }

            try {
                return ($this->fallback)($failure, $context);
            } catch (Throwable $fallbackFailure) {
                throw new ResilienceFallbackException($context->operation(), $failure, $fallbackFailure);
            }
        }
    }
}
