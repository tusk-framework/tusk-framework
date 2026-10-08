# Tusk Resilience Core Design

**Date:** 2026-10-06  
**Status:** Approved design; implementation pending  
**Issue:** Framework #10

## Goal

Provide a PHP-native, provider-neutral resilience core for outbound operations,
jobs, and infrastructure services running inside persistent RoadRunner workers.
The common path must be explicit, bounded, deterministic in tests, and safe for
non-idempotent operations.

The first implementation extends the existing `tusk-cloud` package and adds
small contracts to `tusk-contracts`. It does not add a second runtime, proxy,
gateway, worker pool, or mandatory external service.

## Success criteria

- Programmatic APIs support retry, timeout/deadline, circuit breaking,
  concurrency bulkheads, and rate limiting.
- Policies compose around closures, jobs, and PSR-18 clients without requiring
  attributes or reflection.
- Retry defaults do not retry non-idempotent operations without an explicit
  idempotency declaration.
- Circuit and limiter state is scoped to an operation and worker, with an
  injectable state store for durable or shared deployments.
- No queue is unbounded and no policy silently discards the original failure.
- Events, metrics, and traces are optional and provider-neutral.
- Configuration errors fail before the worker starts.
- Tests cover state machines, deadlines, backoff, classification, bounded
  concurrency, rate limits, persistence, and failure propagation without a
  network or external collector.

## Scope

### In scope

- Immutable policy value objects and validation.
- A monotonic deadline and cancellation context.
- Exception/status classification with allow and deny rules.
- Fixed, exponential, and decorrelated-jitter backoff with injectable clock
  and sleeper.
- Retry executor with maximum attempts, total deadline, idempotency guardrails,
  and explicit fallback/failure behavior.
- Circuit breaker with `closed`, `open`, and `half_open` states.
- Bounded concurrency bulkhead with explicit reject behavior and no unbounded
  wait queue.
- Token-bucket rate limiter with bounded state and explicit rejection.
- PSR-18 middleware/decorator composition for timeout, retry, circuit breaker,
  bulkhead, and trace propagation.
- Optional resilience events and metrics through existing provider-neutral
  contracts.
- Configuration binding and validation for named operation/service policies.

### Out of scope for the first delivery

- PHP attributes and container compilation for resilience policies.
- Hedging, adaptive concurrency, distributed consensus, or automatic worker
  recycling.
- A new ORM, service mesh, gateway, or Engine data-plane feature.
- A mandatory Redis/database dependency for state.
- Vendor-specific resilience libraries in public contracts.
- Automatic mutation of application requests or implicit retries of arbitrary
  handlers.

## Package boundaries

### `tusk-contracts`

Contracts contain behavior-neutral types only:

- `Resilience\ClockInterface` for monotonic time and sleeping.
- `Resilience\DeadlineInterface` for remaining budget and expiration.
- `Resilience\OperationContext` carrying operation name, deadline,
  retry authorization, immutable metadata, and an optional cooperative
  `CancellationTokenInterface`.
- `Resilience\FailureClassifierInterface` for exception/status decisions.
- `Resilience\StateStoreInterface` remains the existing persistence boundary;
  resilience-specific keys use a namespaced operation key.
- `Resilience\ResilienceEventDispatcherInterface` and
  `Resilience\ResilienceMetricsInterface` as optional observation ports, or
  adapters over the existing event/telemetry contracts.

Contracts must not import Guzzle, OpenTelemetry SDK classes, RoadRunner types,
Symfony components, or concrete storage implementations.

### `tusk-cloud`

`tusk-cloud/src/Resilience` owns the default implementations:

- `RetryPolicy`, `CircuitBreakerPolicy`, `BulkheadPolicy`, and
  `RateLimitPolicy` immutable configuration objects.
- `RetryExecutor` and `ResiliencePipeline` for explicit programmatic use.
- `CircuitBreaker` state machine and state-store adapter.
- `Bulkhead` and token-bucket `RateLimiter`.
- `FailureClassifier` and idempotency policy.
- `Psr18ResilienceMiddleware`/decorator composition.
- No-op event and metric sinks when none are configured.

Existing `tusk-cloud/src/Resilience/CircuitBreaker.php`, `Retry.php`, and
`InMemoryStateStore.php` are evolved or replaced behind the new contracts; no
duplicate circuit-breaker abstraction is kept.

## Programmatic API

The primary API is explicit and usable without attributes:

```php
$pipeline = $resilience->pipeline('payments.charge')
    ->withRetry($retryPolicy)
    ->withCircuitBreaker($circuitPolicy)
    ->withBulkhead($bulkheadPolicy)
    ->withRateLimit($rateLimitPolicy);

$receipt = $pipeline->run(
    operation: fn (OperationContext $context): Receipt => $payments->charge($payment),
    context: OperationContext::idempotent('payments.charge'),
);
```

The pipeline must also accept a non-idempotent context. In that case retries
are disabled unless the caller supplies an explicit idempotency key and policy
authorization. The closure receives the same deadline/cancellation context on
each attempt; it does not receive a mutated global state.

The first API should favor named constructors and immutable objects over fluent
magic where a fluent call would hide a policy decision. A convenience builder
may exist, but the underlying executor and policy types remain directly
constructible and testable.

## Policy semantics

### Retry

- `maxAttempts` includes the first call and must be at least one.
- `deadline` bounds the complete operation, including backoff.
- `backoff` supports fixed, exponential, and decorrelated jitter.
- Jitter uses an injectable random source so tests can be deterministic.
- `retryOn` and `doNotRetryOn` are evaluated in deny-first order.
- HTTP status classification is explicit and defaults to transient `408`,
  `425`, `429`, and `5xx`; `4xx` is not retried by default.
- A retry event includes operation, attempt, delay, remaining budget, and
  classification, but never request bodies or credentials.
- When the budget expires, the original failure or a typed deadline exception
  is propagated with the original failure as its previous exception.

### Circuit breaker

- States are `closed`, `open`, and `half_open`.
- `closed` counts classified failures in a rolling window or consecutive
  policy, as selected by configuration.
- `open` rejects immediately until `openDuration` elapses.
- `half_open` permits a bounded number of probes; concurrent probes beyond the
  configured limit are rejected.
- A successful probe closes the circuit; a failed probe reopens it.
- State transitions are atomic within the selected `StateStoreInterface`.
- The default in-memory store is worker-local and never claims cluster-wide
  coordination.

### Bulkhead

- The default implementation is a bounded semaphore.
- `limit` must be positive.
- `queueLimit` defaults to zero; when positive it is finite and rejects once
  full.
- Waiting consumes the operation deadline and cancellation context.
- Cancellation is cooperative: executors check the token at admission and
  between bounded waits; an already-running user operation must observe the
  same token if it needs in-flight cancellation.
- Release occurs in `finally`, including exceptions and cancellation.

### Rate limiter

- Token-bucket parameters are immutable and validated at boot.
- Capacity and refill rate must be positive and finite.
- Acquisition consumes the operation deadline; no unbounded sleeping occurs.
- Rejection is explicit and observable.
- An expired operation deadline rejects admission even if a token is available.
- Worker-local state is the default; shared state requires an injected store.

## HTTP integration

The PSR-18 integration is a decorator/middleware that receives a configured
pipeline and an underlying `Psr\Http\Client\ClientInterface`.

- Request timeout/deadline is mapped to the operation context.
- Retry classification uses response status and transport exceptions.
- Request bodies are never replayed unless the body is rewindable and the
  policy explicitly permits replay.
- Unsafe methods (`POST`, `PATCH`, and custom methods) are not retried by
  default; an idempotency key and explicit policy are required.
- Trace context is delegated to the existing observability bridge when
  available; resilience does not import an OpenTelemetry SDK.
- The decorator returns the original response or throws a typed resilience
  exception with the original cause preserved.

## Persistent-worker safety

- No static mutable circuit, limiter, or bulkhead state.
- Worker-scoped instances are reset on worker stop through the existing
  lifecycle/module boundary.
- Request-scoped operation context is never retained after the pipeline exits.
- Every acquired bulkhead slot and every rate-limiter reservation is released
  or finalized in `finally`.
- Metrics and events are bounded and must not retain request objects, bodies,
  exceptions with sensitive messages, or arbitrary closures.

## Events, metrics, and diagnostics

Observation is optional and uses existing Tusk provider-neutral boundaries.

Events:

- `resilience.retry.scheduled`
- `resilience.retry.exhausted`
- `resilience.circuit.opened`
- `resilience.circuit.half_opened`
- `resilience.circuit.closed`
- `resilience.bulkhead.rejected`
- `resilience.rate_limit.rejected`
- `resilience.fallback.used`

Metrics use stable names and bounded labels: operation, policy, outcome, and
coarse status/classification. No URL, user ID, exception message, or arbitrary
label is accepted.

Diagnostics expose configured policy names and aggregate counters only. They do
not expose circuit internals, credentials, request bodies, or unbounded state.

## Configuration

Configuration is represented as typed policy objects after validation. A
configuration shape may look like:

```php
return [
    'resilience' => [
        'operations' => [
            'payments.charge' => [
                'retry' => [
                    'max_attempts' => 3,
                    'backoff' => 'exponential',
                    'max_delay_ms' => 500,
                ],
                'circuit_breaker' => [
                    'failure_threshold' => 5,
                    'open_duration_ms' => 10_000,
                ],
                'bulkhead' => ['limit' => 32, 'queue_limit' => 0],
                'rate_limit' => ['capacity' => 100, 'refill_per_second' => 20],
            ],
        ],
    ],
];
```

Invalid policy names, non-positive limits, malformed durations, invalid
classifiers, and incomplete deadline settings fail during application boot or
`tusk config:validate`. Defaults are conservative: one attempt, no queue,
no automatic fallback, and no retry of unsafe operations.

## Failure handling

- Policy rejection is a typed exception and is never silently converted to a
  success response.
- Fallbacks are explicit callables and run only when configured.
- Fallback exceptions preserve the original failure as their previous cause.
- Event/metric sink failures never replace the operation failure; they are
  recorded through the existing diagnostics path.
- State-store failures fail closed for circuit/rate-limit decisions rather than
  pretending that coordination succeeded.

## Testing strategy

All policy tests use fake clock, fake sleeper, fake random source, and in-memory
state stores. No test requires an external collector, database, Redis, or HTTP
server.

Required coverage:

- retry attempt limits, classification, deadlines, fixed/exponential/
  decorrelated jitter, and original-cause preservation;
- circuit state transitions, half-open probe limits, concurrent rejection,
  state-store round trips, and worker-local isolation;
- bulkhead acquisition/release, bounded queue, cancellation, deadline expiry,
  and release after exceptions;
- rate limiter refill, capacity, deadline, rejection, and state reset;
- PSR-18 safe-method defaults, rewindable body guardrails, status mapping, and
  trace propagation adapter behavior;
- configuration validation and deterministic generated diagnostics;
- optional event/metric sink failures not masking operation failures;
- persistent-worker lifecycle reset of policy state.

## Delivery phases

1. **Contracts and primitives:** contexts, policies, clocks, classifiers, typed
   exceptions, and deterministic tests.
2. **Executors:** retry, circuit breaker, bulkhead, and rate limiter with
   programmatic pipeline composition.
3. **HTTP and observability integration:** PSR-18 decorator, bounded events,
   metrics, and diagnostics.
4. **Runtime/configuration integration:** boot validation, worker-scoped module,
   lifecycle reset, and generated examples.
5. **Declarative layer:** attributes and compiled metadata only after the
   programmatic API is stable and documented.

Each phase is independently testable and can be merged without requiring the
next phase. The Engine remains unchanged unless a later, separately approved
diagnostics contract is needed.

### First programmatic core implementation notes

- A constructed pipeline owns worker-local bulkhead and rate-limit state and
  should be built once at application bootstrap and reused.
- A circuit observes the final retry outcome through the configured failure
  classifier. Cancellation, caller deadline expiry, and rate-limit rejection
  do not count as circuit failures under the default classifier.
- Circuit state reads required to admit an operation fail closed. Errors while
  recording an outcome after user code has completed never replace the
  operation's result or throwable; a failed half-open close attempts to reopen
  the circuit so a transient store error does not strand the probe budget.

## Alternatives rejected

- **New `tusk-resilience` package now:** clean isolation, but duplicates the
  existing `tusk-cloud` boundary and delays useful integration.
- **Provider-specific implementation:** faster initially, but leaks SDK types
  into contracts and makes worker-safe testing difficult.
- **Attribute-first implementation:** attractive syntax, but hides policy
  semantics and creates reflection/runtime coupling before the core is proven.
- **Engine/gateway enforcement:** would duplicate application-level policy and
  violate the Engine/RoadRunner control-plane boundary.
