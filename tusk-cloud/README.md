# Tusk Cloud

The **Tusk Cloud** is a toolkit for building distributed systems and cloud-native applications.

## Features
- **Resilience**: Circuit Breaker, Retries, and Timeouts.
- **Service Discovery**: Integration with Consul and Kubernetes.
- **Observability**: Health checks and metrics publishing.

### Programmatic resilience

Resilience policies can be composed around a closure without attributes or runtime reflection. Each pipeline owns worker-local bulkhead and rate-limit state, so construct it once during application bootstrap and reuse it; creating multiple pipelines creates independent budgets. Retry is one attempt by default, and the default failure classifier is terminal.

```php
use Tusk\Cloud\Resilience\Backoff\ExponentialBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

$resilience = new ResiliencePipelineFactory(new SystemClock(), new InMemoryStateStore());
$payments = $resilience->pipeline('payments.charge')
    ->withRetry(RetryPolicy::create(
        maxAttempts: 3,
        backoffStrategy: ExponentialBackoff::create(100, 2_000),
        classifier: new class implements FailureClassifierInterface {
            public function classify(\Throwable $failure, OperationContext $context): FailureDecision
            {
                return FailureDecision::retryable();
            }
        },
    ))
    ->withCircuitBreaker(CircuitBreakerPolicy::create())
    ->withBulkhead(BulkheadPolicy::create(maxConcurrent: 32))
    ->withRateLimit(RateLimitPolicy::create(capacity: 100, refillPerSecond: 20));

$receipt = $payments->run(
    operation: fn (OperationContext $context) => $gateway->charge($payment),
    context: OperationContext::create('payments.charge', retryAllowed: true),
);
```

The pipeline holds the bulkhead for one logical operation, checks the circuit once for that operation, and charges the rate limiter before every attempt. The retry executor sees the final operation failure and preserves its original throwable. The same classifier controls retry eligibility and whether the final failure counts toward the circuit; the default classifier excludes rate-limit rejection, deadline expiry, and cancellation from circuit failures. Fallbacks are explicit and receive the failure and operation context. A fallback failure is wrapped while retaining both the original and fallback exceptions.

Queued bulkhead work requires an operation deadline and can also observe a cooperative `CancellationTokenInterface` on `OperationContext`. Without a deadline, excess work is rejected immediately; queue size defaults to zero. The in-memory circuit store coordinates only within the worker process. HTTP middleware, framework configuration, metrics/events, and declarative attributes are follow-up phases and are not included in this programmatic core.

## Installation
```bash
composer require tusk/cloud
```
