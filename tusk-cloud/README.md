# Tusk Cloud

The **Tusk Cloud** is a toolkit for building distributed systems and cloud-native applications.

## Features
- **Resilience**: Circuit Breaker, Retries, and Timeouts.
- **Service Discovery**: Integration with Consul and Kubernetes.
- **Observability**: Health checks and metrics publishing.

### Declarative named configuration

New projects include `config/resilience.php`. Define only the mechanisms a named operation needs; omitted sections remain disabled:

```php
<?php

return [
    'policies' => [
        'catalog.fetch' => [
            'retry' => [
                'max_attempts' => 3,
                'backoff' => [
                    'type' => 'exponential',
                    'base_delay_ms' => 100,
                    'max_delay_ms' => 2_000,
                ],
                'retry_on' => [\RuntimeException::class],
            ],
            'circuit_breaker' => ['failure_threshold' => 5],
            'bulkhead' => ['max_concurrent' => 16],
            'rate_limit' => ['capacity' => 100, 'refill_per_second' => 20],
        ],
    ],
    'profiles' => [
        'production' => [
            'policies' => [
                'catalog.fetch' => ['retry' => ['max_attempts' => 4]],
            ],
        ],
    ],
];
```

At boot, Tusk validates the base policy and the active `APP_ENV` profile (`production` when unset) before constructing runtime modules, then exposes the immutable `ResilienceConfiguration` from the application container. The optional `profiles` map may be omitted. Profiles recursively merge associative maps; lists and scalar values replace the base value. Unknown keys, invalid bounds, and class names that do not implement `Throwable` fail with a configuration path and do not echo the supplied value.

Run the same local validation without starting the application or RoadRunner:

```bash
php bin/tusk config:validate
php bin/tusk config:validate --profile=staging
```

### Runtime diagnostics for Engine

The application container binds one worker-local `Tusk\Cloud\Resilience\Diagnostics\ResilienceRuntime`. Resolve a named policy through `$container->get(ResilienceRuntime::class)->pipeline('catalog.fetch')`; this uses the validated active `APP_ENV` profile. The runtime records the resolved policy name and enabled features (`retry`, `circuit_breaker`, `bulkhead`, `rate_limit`). `$runtime->diagnostics()` returns a detached snapshot of resolved policies and circuit states. A configured circuit remains `unknown` until its worker-local state store confirms `closed`, `open`, or `half_open`. This state is process memory, not persistent or shared across workers.

When Tusk Engine supplies both `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL` and `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN`, the optional reporter posts a complete snapshot to Engine's private listener. The URL must be an exact `http://127.0.0.1:<port>` base address. The reporter sends at worker start, after confirmed circuit transitions, and at worker stop. Completed requests and jobs trigger a coalesced heartbeat no more often than once every 15 seconds. Engine treats observations as stale after a 60-second lease. If either environment value is missing, the address is invalid, or PHP's curl extension is unavailable, reporting is a no-op.

Diagnostic names are public labels. Use only ASCII letters, digits, underscore, dash, and dot, up to 128 bytes. If a resolved name violates this rule, or the snapshot exceeds Engine's 256-policy, 256-circuit, or 64 KiB bounds, the worker sends no snapshot rather than publishing a partial view; policy execution still works. Reports contain only policy names, enabled features, and circuit states. They exclude settings, throwable names and messages, request/response data, credentials, and process IDs. The opaque worker identifier and token are sent only to the private receiver and are not part of application metadata or logs.

Each report makes one attempt with a 50 ms total timeout and redirects disabled. A failed or malformed response is swallowed; reporting cannot change a request, job, or policy result. Direct `new ResiliencePipelineFactory(...)` use keeps its existing semantics and is not registered as Engine-managed diagnostics.

Retry remains one attempt by default and unsafe operations remain non-retryable unless `allow_unsafe_retries` is explicitly enabled. `retry_on` is an optional allow-list, `do_not_retry_on` is an optional deny-list that takes precedence, and omitting the allow-list preserves the existing default classifier. Keep retries limited to failures and operations that are safe to repeat.

The readiness endpoint includes the local `resilience_configuration` check, which is registered only after successful boot validation and makes no network calls. Liveness remains an unconditional local `UP` response and does not execute readiness checks. In a persistent worker, keep configured pipelines reusable but create an `OperationContext` for each logical request/operation; its cancellation, deadline, and metadata are not retained by the shared configuration.

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

Queued bulkhead work requires an operation deadline and can also observe a cooperative `CancellationTokenInterface` on `OperationContext`. Without a deadline, excess work is rejected immediately; queue size defaults to zero. The in-memory circuit store coordinates only within the worker process.

### Optional resilience events and metrics

Pass either or both existing contracts to the factory during bootstrap. Both arguments default to `null`; existing factory construction remains compatible. Reuse this factory with programmatic pipelines and `Psr18ResilientClient` to observe the same resilience lifecycle.

```php
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

/** @var EventDispatcherInterface $eventDispatcher */
/** @var TelemetryProviderInterface $telemetry */
$resilience = new ResiliencePipelineFactory(
    clock: new SystemClock(),
    stateStore: new InMemoryStateStore(),
    eventDispatcher: $eventDispatcher,
    telemetry: $telemetry,
);
```

Typed immutable events live in `Tusk\Cloud\Resilience\Event`:

- `RetryScheduled`: operation name, failed attempt number, planned delay in milliseconds, and failure class. Emitted only after another attempt is authorized, immediately before backoff.
- `CircuitStateChanged`: operation name and previous/current `State`, emitted after an accepted persisted transition.
- `FallbackApplied`: operation name and original failure class, emitted when fallback starts.
- `OperationRejected`: operation name and `OperationRejectionReason`. Only `CircuitOpenException`, `BulkheadRejectedException`, `BulkheadTimeoutException`, `RateLimitRejectedException`, `ResilienceDeadlineExceededException`, and `OperationCancelledException` map to `circuit_open`, `bulkhead_rejected`, `bulkhead_timeout`, `rate_limit_rejected`, `deadline_exceeded`, and `cancelled`, respectively. Unrelated application failures remain ordinary failures.

Metrics use fixed names and bounded labels:

| Metric | Recording | Labels |
| --- | --- | --- |
| `tusk.resilience.operations` | Counter, once per logical `run()` | `outcome`: `success`, `failure`, `fallback_success`, `fallback_failure` |
| `tusk.resilience.retries` | Counter, once per scheduled retry | None |
| `tusk.resilience.rejections` | Counter, once per recognized rejection | `reason`: the six values above |
| `tusk.resilience.circuit.transitions` | Counter, once per state transition | `from`, `to`: `CLOSED`, `OPEN`, `HALF_OPEN` |
| `tusk.resilience.operation.duration` | Observation in elapsed seconds | `outcome`: the same four values as the operations counter |

Custom backoff delays should be nonnegative. A negative delay retains legacy retry execution behavior but emits neither `RetryScheduled` nor a `tusk.resilience.retries` increment.

Duration uses the factory's monotonic clock around the full logical invocation, including retries, backoff, policy waits, and fallback. Fallback success/failure produces one final outcome rather than a separate operation count. A failed fallback still throws `ResilienceFallbackException` retaining both failures. For PSR-18, the outcome describes the pipeline: an exhausted transient response records `failure` even though the decorator returns that exact final response to the caller.

Dispatch and metric calls are synchronous and can add latency. Listeners and providers should be fast; applications needing asynchronous export can supply their own bounded queue adapter. Each sink call catches `Throwable` independently: a listener failure cannot suppress a metric attempt, and a counter failure cannot suppress duration observation. Sink failures are swallowed without replacing operation results, original operation/policy failures, or fallback results/failures; sink calls are never retried. Clock failures disable duration recording while preserving outcome-counter attempts. The application/runtime owns provider flushing and shutdown; the factory does neither.

Metric labels never contain operation names, URLs, exception classes/messages, user IDs, credentials, request/response bodies, or arbitrary context metadata. Events contain only the fields listed above, with failure class names rather than messages, traces, or throwable objects. Choose static, nonsensitive operation identifiers: event operation names are application-provided. Event consumers remain responsible for their own privacy and retention policies. No exporter, additional package dependency, or configuration key is required.

### Resilient outbound PSR-18 client

`Psr18ResilientClient` decorates any PSR-18 client and reuses one configured pipeline for its lifetime. Retry is one attempt by default; transient responses (`408`, `425`, `429`, and `5xx`) are retryable when the request is safe to replay. The default idempotent methods are `GET`, `HEAD`, `OPTIONS`, `TRACE`, `PUT`, and `DELETE`. Unsafe methods such as `POST` require both `allowUnsafeRetries: true` and exactly one non-empty `Idempotency-Key` value. Request bodies must be seekable with a readable starting position; non-empty or unknown-size bodies additionally require explicit replay opt-in.

```php
use Tusk\Cloud\Resilience\Backoff\ExponentialBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Http\Psr18ResilientClient;
use Tusk\Cloud\Resilience\Http\RequestReplayPolicy;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

$http = new Psr18ResilientClient(
    client: $psr18Client,
    pipelineFactory: $resilience,
    operation: 'catalog.fetch',
    retryPolicy: RetryPolicy::create(
        maxAttempts: 3,
        backoffStrategy: ExponentialBackoff::create(100, 1_000),
        classifier: new class implements FailureClassifierInterface {
            public function classify(\Throwable $failure, OperationContext $context): FailureDecision
            {
                return FailureDecision::retryable();
            }
        },
    ),
    replayPolicy: RequestReplayPolicy::create(),
    circuitBreakerPolicy: CircuitBreakerPolicy::create(),
    bulkheadPolicy: BulkheadPolicy::create(maxConcurrent: 16),
    rateLimitPolicy: RateLimitPolicy::create(capacity: 100, refillPerSecond: 20),
);

$response = $http->sendRequest($request);
```

The retry classifier is explicit; the HTTP integration classifies transient statuses and network failures. Configure connection and request timeouts on the injected HTTP client: PSR-18 has no standard API for interrupting an in-flight request. Operation deadlines and cancellation are checked before dispatch and between attempts, not by forcibly stopping the transport. A context factory can supply a deadline/cancellation token and veto retries. Optional events and metrics configured on `$resilience` also cover this client, without HTTP-specific labels or duplicate accounting. Declarative policy configuration, boot validation, and local readiness are documented above; trace-header propagation remains a follow-up tracked by [issue #29](https://github.com/tusk-framework/tusk-framework/issues/29).

## Installation
```bash
composer require tusk/cloud
```
