# Resilience Events and Metrics Design

## Purpose

Add optional operational visibility to Tusk Cloud's resilience pipeline so applications can observe retries, fallbacks, rejections, circuit transitions, and aggregate operation outcomes without coupling the cloud package to an exporter or runtime. This is the next discrete slice of framework issue #29; configuration binding, health/readiness, and trace-header propagation remain out of scope.

## Goals

- Emit typed, provider-neutral events for retry scheduling, fallback use, admission rejection, and circuit-breaker state transitions.
- Record bounded counters and operation-duration observations through the existing `TelemetryProviderInterface`.
- Reuse the existing `EventDispatcherInterface` and `TelemetryProviderInterface`; do not add a mandatory dependency or a second generic metrics abstraction.
- Make both event and metric recording best-effort: any sink/listener exception must not replace an operation result, the original operation exception, a policy rejection, or a fallback result/failure.
- Preserve current behavior when neither sink is configured.
- Keep metric dimensions fixed and low-cardinality; never label metrics with operation names, URLs, exception messages/classes, user IDs, or arbitrary context metadata.

## Non-goals

- Creating exporters, storage, dashboards, alerting, or lifecycle flush/shutdown behavior. The application/runtime remains the owner of the injected telemetry provider lifecycle.
- Changing retry/circuit/bulkhead/rate-limit semantics, event listener ordering, or the PSR-18 client API.
- Emitting raw exception messages, request data, or arbitrary `OperationContext` metadata.
- Completing issue #29: typed config/boot validation, health/readiness, and trace propagation remain follow-up work.

## Existing architecture and constraints

`ResiliencePipelineFactory` constructs a reusable pipeline from `RetryExecutor`, `CircuitBreaker`, `Bulkhead`, and `RateLimiter`. `ResiliencePipeline` owns the logical operation/fallback boundary, `RetryExecutor` sees each failed attempt and backoff, and `CircuitBreaker` owns its state transitions. The contracts package already exposes `EventDispatcherInterface` (PSR event dispatch) and `TelemetryProviderInterface` (counter increments and value observations). The cloud package depends on contracts only; it must not depend on `tusk-events`, `tusk-runtime`, OpenTelemetry SDKs, or RoadRunner.

## Design

### Optional instrumentation composition

Add optional `?EventDispatcherInterface` and `?TelemetryProviderInterface` dependencies to `ResiliencePipelineFactory`. The factory builds one internal resilience instrumentation adapter and passes it through the immutable builder into the pipeline and the retry/circuit components that need lifecycle visibility. The adapter owns independent safe-dispatch and safe-metric methods; each catches `Throwable` around each sink operation so a failure in one sink does not prevent recording to the other. There is no implicit no-op provider allocation when both dependencies are absent.

The adapter does not flush or shut down providers. It does not retry sink calls, since retries could amplify telemetry outages or perturb request latency. Sink failures are suppressed at this boundary and are not reclassified as application/policy failures.

### Event contracts

Add immutable event value objects under `Tusk\Cloud\Resilience\Event`:

- `RetryScheduled`: operation name, failed attempt number, selected delay in milliseconds, and failure type (class name only; no message or trace).
- `FallbackApplied`: operation name and original failure type.
- `OperationRejected`: operation name and a fixed reason enum (`circuit_open`, `bulkhead_rejected`, `bulkhead_timeout`, `rate_limit_rejected`, `deadline_exceeded`, `cancelled`).
- `CircuitStateChanged`: operation name and previous/current `State` enum values.

Dispatch events synchronously through the existing PSR dispatcher. The dispatcher/listener is an optional application integration point; listeners must not be able to change resilience outcomes because adapter errors are isolated.

### Emission boundaries

- `RetryExecutor` dispatches `RetryScheduled` only after classification and retry-budget/deadline checks approve another attempt, immediately before backoff. It records the planned delay, not actual wall-clock sleep.
- `CircuitBreaker` dispatches `CircuitStateChanged` only when its persisted state transitions among CLOSED, OPEN, and HALF_OPEN. Events are emitted after the in-memory/store transition is accepted; store failures retain existing circuit behavior and must not be reclassified as telemetry failures.
- `ResiliencePipeline` dispatches `FallbackApplied` when fallback is invoked and `OperationRejected` for known admission/deadline/cancellation rejections. It records one final outcome for each logical invocation, including fallback success/failure.
- The event layer reports the original failure category/type but does not replace or wrap the failure.

### Metrics contract

Use only the existing `TelemetryProviderInterface::increment()` and `observe()` methods with these names and bounded attributes:

- `tusk.resilience.operations`: increment once per logical pipeline invocation; `outcome` is one of `success`, `failure`, `fallback_success`, or `fallback_failure`.
- `tusk.resilience.retries`: increment once per scheduled retry; no dynamic attributes.
- `tusk.resilience.rejections`: increment for known admission/deadline/cancellation rejection; `reason` is one of the fixed `OperationRejected` reason values.
- `tusk.resilience.circuit.transitions`: increment on state change; `from` and `to` are values of the three-state enum, yielding at most nine combinations.
- `tusk.resilience.operation.duration`: observe elapsed seconds for each logical invocation; `outcome` uses the same four values as `tusk.resilience.operations`.

Duration uses the factory's existing monotonic `ClockInterface` and is measured around one logical `ResiliencePipeline::run()`, including retries/backoff and fallback. Metrics do not include operation names or any user-provided fields. Only recognized rejection exception types map to rejection metrics; unrelated application exceptions remain ordinary failures.

### PSR-18 integration

`Psr18ResilientClient` already retains one pipeline per decorated client, so telemetry configured on its shared `ResiliencePipelineFactory` automatically covers HTTP attempts and circuit/fallback events. No HTTP-specific telemetry adapter, duplicate metrics, URL/method dimensions, or change to PSR-18 response behavior is added.

## Failure handling and privacy

- Event listener failures and telemetry provider failures are caught independently and never escape the instrumentation adapter.
- The observer does not catch or transform operation/policy exceptions except to record best-effort outcome telemetry before rethrow/fallback completion.
- Metrics use fixed names and enumerated low-cardinality dimensions. Events may include the configured operation name and failure class for application-side diagnosis, but never exception messages, request/response bodies, URLs, credentials, or arbitrary operation metadata.
- If event dispatch fails, metric recording is still attempted, and vice versa.

## Compatibility

The change is additive. Existing factory construction remains source-compatible by adding nullable optional arguments at the end. Existing pipelines with no instrumentation retain their behavior. No new package dependency or configuration key is introduced.

## Acceptance criteria

- Tests verify the expected event and metric for each supported emission boundary and exactly-once logical outcome accounting.
- Tests prove failures from event dispatch and telemetry recording independently do not mask successful values, original failures, admission rejections, or fallback results.
- Tests verify retries are recorded only when a retry is actually scheduled, not for terminal failures or exhausted budgets.
- Circuit transition tests cover CLOSED→OPEN, OPEN→HALF_OPEN, HALF_OPEN→CLOSED, and HALF_OPEN→OPEN without duplicate transition events.
- Metric tests assert all names/attributes and prove operation names, exception details, and user metadata do not enter metric dimensions.
- PSR-18 integration tests prove the same configured pipeline emits telemetry while retaining existing retry and final-response semantics.
- Full framework tests, PHPStan, Pint on touched PHP, Composer validation, and CI PHP 8.2/8.3/8.4 pass.

## Risks and mitigations

- **Observer placement can alter semantics:** keep instrumentation in narrow best-effort methods; test thrown sinks at success and failure boundaries.
- **Circuit state has concurrent transitions:** emit only at the exact accepted state-write points and test existing generation/probe races alongside events.
- **Metric cardinality can grow:** use only fixed enums and omit operation/user/request values from metric attributes.
- **Event consumers can leak sensitive data:** provide only operation identifier and failure class, never exception message or HTTP payload.
- **Instrumentation can add synchronous latency:** keep calls optional and document that dispatch is synchronous; users needing asynchronous export should queue/listen through their own bounded adapter.

## Deferred work

Issue #29 remains open after this slice. Typed named-policy configuration and boot validation, health/readiness contributors, and trace-header propagation require separate design and implementation phases. Engine diagnostics remain out of scope.
