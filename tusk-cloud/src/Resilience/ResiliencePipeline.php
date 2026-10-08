<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Closure;
use Throwable;
use Tusk\Cloud\Resilience\Event\FallbackApplied;
use Tusk\Cloud\Resilience\Event\OperationRejected;
use Tusk\Cloud\Resilience\Event\OperationRejectionReason;
use Tusk\Cloud\Resilience\Exception\BulkheadRejectedException;
use Tusk\Cloud\Resilience\Exception\BulkheadTimeoutException;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
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
        private ?ResilienceInstrumentation $instrumentation = null,
    ) {}

    public function run(callable $operation, ?OperationContext $context = null): mixed
    {
        $context ??= OperationContext::create($this->name);
        $startedAt = $this->instrumentation?->beginOperation();
        $outcome = 'success';

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
            $outcome = 'failure';
            $reason = match (true) {
                $failure::class === CircuitOpenException::class => OperationRejectionReason::CIRCUIT_OPEN,
                $failure::class === BulkheadRejectedException::class => OperationRejectionReason::BULKHEAD_REJECTED,
                $failure::class === BulkheadTimeoutException::class => OperationRejectionReason::BULKHEAD_TIMEOUT,
                $failure::class === RateLimitRejectedException::class => OperationRejectionReason::RATE_LIMIT_REJECTED,
                $failure::class === ResilienceDeadlineExceededException::class => OperationRejectionReason::DEADLINE_EXCEEDED,
                $failure::class === OperationCancelledException::class => OperationRejectionReason::CANCELLED,
                default => null,
            };
            if ($reason !== null) {
                $this->instrumentation?->operationRejected(new OperationRejected($context->operation(), $reason));
            }

            if ($this->fallback === null) {
                throw $failure;
            }

            $this->instrumentation?->fallbackApplied(new FallbackApplied($context->operation(), $failure::class));
            try {
                $result = ($this->fallback)($failure, $context);
                $outcome = 'fallback_success';

                return $result;
            } catch (Throwable $fallbackFailure) {
                $outcome = 'fallback_failure';
                throw new ResilienceFallbackException($context->operation(), $failure, $fallbackFailure);
            }
        } finally {
            $this->instrumentation?->finishOperation($startedAt, $outcome);
        }
    }
}
