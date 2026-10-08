# Resilience Events and Metrics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional, failure-isolated events and bounded metrics to Tusk Cloud's resilience pipeline.

**Architecture:** Reuse the existing contracts `EventDispatcherInterface` and `TelemetryProviderInterface`, passed optionally to `ResiliencePipelineFactory`. A single internal instrumentation adapter safely dispatches typed resilience events and records fixed low-cardinality metrics; pipeline, retry executor, and circuit breaker call it at their respective lifecycle boundaries.

**Tech Stack:** PHP 8.2+, Tusk Contracts, PSR Event Dispatcher, existing Tusk Cloud resilience pipeline, PHPUnit 10, PHPStan 2.2, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-07-resilience-events-metrics-design.md`

## Global Constraints

- Reuse the existing `EventDispatcherInterface` and `TelemetryProviderInterface`; do not add a mandatory dependency or a second generic metrics abstraction.
- Make both event and metric recording best-effort: any sink/listener exception must not replace an operation result, the original operation exception, a policy rejection, or a fallback result/failure.
- Preserve current behavior when neither sink is configured.
- Keep metric dimensions fixed and low-cardinality; never label metrics with operation names, URLs, exception messages/classes, user IDs, or arbitrary context metadata.
- Do not flush or shut down providers; lifecycle remains owned by the application/runtime.
- Do not emit exception messages, request/response data, credentials, or arbitrary context metadata.
- Do not change retry/circuit/bulkhead/rate-limit semantics, event listener ordering, or the PSR-18 client API.
- The cloud package depends on contracts only; do not depend on `tusk-events`, `tusk-runtime`, OpenTelemetry SDKs, or RoadRunner.
- Issue #29 remains open; typed configuration/boot validation, health/readiness, and trace-header propagation are out of scope.

## Review Focus

- If an event listener or telemetry provider throws during success, failure, rejection, or fallback, the original operation outcome must be preserved; Task 2 and Task 4 tests exercise both sinks independently.
- A retry is observed only after classifier, retry budget, and deadline checks authorize it; terminal and exhausted failures emit no retry event/counter; Task 3 tests pin this.
- Half-open probes and concurrent state changes must not produce duplicate or false circuit transitions; Task 3 adds transition assertions to breaker tests.
- Operation names, exception details, request data, and arbitrary context metadata must never enter metric attributes; Task 2 asserts exact attributes.
- Fallback failure and recognized policy rejection must retain existing exception identity/wrapping semantics while recording one final outcome; Task 4 tests both paths.

---

### Task 1: Define immutable resilience event contracts

**Files:**

- Create: `tusk-cloud/src/Resilience/Event/OperationRejectionReason.php`.
- Create: `tusk-cloud/src/Resilience/Event/RetryScheduled.php`.
- Create: `tusk-cloud/src/Resilience/Event/FallbackApplied.php`.
- Create: `tusk-cloud/src/Resilience/Event/OperationRejected.php`.
- Create: `tusk-cloud/src/Resilience/Event/CircuitStateChanged.php`.
- Test: `tusk-cloud/tests/Resilience/Event/ResilienceEventTest.php`.

**Interfaces:**

- `OperationRejectionReason: string` enum cases: `CIRCUIT_OPEN='circuit_open'`, `BULKHEAD_REJECTED='bulkhead_rejected'`, `BULKHEAD_TIMEOUT='bulkhead_timeout'`, `RATE_LIMIT_REJECTED='rate_limit_rejected'`, `DEADLINE_EXCEEDED='deadline_exceeded'`, `CANCELLED='cancelled'`.
- `RetryScheduled::__construct(string $operation, int $failedAttempt, int $delayMilliseconds, string $failureType)`; operation must be nonblank, attempt >= 1, delay >= 0, failure type nonblank.
- `FallbackApplied::__construct(string $operation, string $failureType)`; operation and failure type must be nonblank.
- `OperationRejected::__construct(string $operation, OperationRejectionReason $reason)`.
- `CircuitStateChanged::__construct(string $operation, State $previous, State $current)`; previous and current must differ.
- Event instances expose these values as public readonly properties named exactly as the constructor parameters.
- All event classes are `final readonly` and contain no arbitrary metadata, Throwable, message, URL, or request/response values.

- [ ] **Step 1: Write failing tests** for exact enum values, readonly event payload access, invalid blank operation/type, invalid retry attempt/delay, and rejection of a same-state circuit transition.
- [ ] **Step 2: Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/Event/ResilienceEventTest.php`** and verify the expected missing-class/behavior failures.
- [ ] **Step 3: Implement the enum and four immutable event classes** with only the validation specified above.
- [ ] **Step 4: Rerun the focused event test** and verify all cases pass.
- [ ] **Step 5: Run Pint and PHPStan** for the new event namespace and test.
- [ ] **Step 6: Commit** as `feat(cloud): define resilience events`.

### Task 2: Add safe event and metric instrumentation adapter

**Files:**

- Create: `tusk-cloud/src/Resilience/ResilienceInstrumentation.php`.
- Test: `tusk-cloud/tests/Resilience/ResilienceInstrumentationTest.php`.

**Interfaces:**

- Consumes Task 1 event classes and existing `EventDispatcherInterface`, `TelemetryProviderInterface`, and `ClockInterface`.
- `ResilienceInstrumentation::__construct(?EventDispatcherInterface $eventDispatcher = null, ?TelemetryProviderInterface $telemetry = null, ?ClockInterface $clock = null)`.
- Methods: `retryScheduled(RetryScheduled $event): void`, `fallbackApplied(FallbackApplied $event): void`, `operationRejected(OperationRejected $event): void`, `circuitStateChanged(CircuitStateChanged $event): void`, `beginOperation(): ?int`, and `finishOperation(?int $startedAt, string $outcome): void`.
- `finishOperation()` accepts only `success`, `failure`, `fallback_success`, or `fallback_failure`; elapsed seconds are `max(0, nowMilliseconds - startedAt) / 1000`.
- Metric names/attributes are exactly those in the spec. `beginOperation()` reads the monotonic clock only when telemetry is configured; clock failures disable only duration observation. `finishOperation()` validates the fixed outcome enum and records the outcome counter once plus duration when a start time exists.
- Each event dispatch and each metric operation catches `Throwable` independently; event sink failure never skips the metric attempt, and metric failure never escapes.

- [ ] **Step 1: Write failing tests** for each metric name/attribute; retry counter; fixed rejection reason; bounded circuit labels; four allowed outcome values; duration in seconds; absence of operation/user/error details in metrics; no-op without sinks; and independently throwing dispatcher, telemetry provider, and clock.
- [ ] **Step 2: Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/ResilienceInstrumentationTest.php`** and verify the expected class-not-found failures.
- [ ] **Step 3: Implement the adapter** with independent best-effort dispatch/metric helpers and the five exact metric names/label sets from the spec.
- [ ] **Step 4: Rerun the focused adapter test** and verify successful results and sink failures are swallowed without cross-sink suppression.
- [ ] **Step 5: Run Pint and PHPStan** on the adapter and its test.
- [ ] **Step 6: Commit** as `feat(cloud): isolate resilience telemetry failures`.

### Task 3: Instrument retries and circuit-breaker state transitions

**Files:**

- Modify: `tusk-cloud/src/Resilience/RetryExecutor.php`.
- Modify: `tusk-cloud/src/Resilience/CircuitBreaker.php`.
- Modify: `tusk-cloud/src/Resilience/ResiliencePipelineFactory.php`.
- Modify: `tusk-cloud/src/Resilience/ResiliencePipelineBuilder.php`.
- Modify: `tusk-cloud/src/Resilience/ResiliencePipeline.php`.
- Test: `tusk-cloud/tests/Resilience/RetryExecutorTest.php`.
- Test: `tusk-cloud/tests/Resilience/CircuitBreakerTest.php`.
- Test: `tusk-cloud/tests/Resilience/ResiliencePipelineTest.php`.

**Interfaces:**

- Consumes Task 1 event types and Task 2 `ResilienceInstrumentation`.
- Extend `ResiliencePipelineFactory::__construct(ClockInterface $clock, StateStoreInterface $stateStore, ?EventDispatcherInterface $eventDispatcher = null, ?TelemetryProviderInterface $telemetry = null)`; construct one instrumentation adapter per factory and share it with pipeline, retry executor, and circuit breaker instances.
- Append nullable `ResilienceInstrumentation` parameters to internal builder/component constructors without changing existing required parameters or public call behavior.
- Retry emits `RetryScheduled` and increments `tusk.resilience.retries` only after retryability, remaining-attempt, and deadline checks pass, immediately before backoff; payload uses failed attempt, selected delay, operation, and exception class only.
- Circuit emits `CircuitStateChanged` and increments the transition counter only after the corresponding state-store write succeeds; transitions are CLOSED→OPEN, OPEN→HALF_OPEN, HALF_OPEN→CLOSED, and HALF_OPEN→OPEN, with no event for unchanged state.

- [ ] **Step 1: Write failing retry tests** asserting scheduled event/counter attempt and delay values, and no event/counter for terminal classification, exhausted attempts, or a deadline that cannot accommodate backoff.
- [ ] **Step 2: Run the focused retry tests** and verify they fail because retry instrumentation is absent.
- [ ] **Step 3: Add optional instrumentation to `RetryExecutor`** and emit only after all existing retry/deadline checks pass.
- [ ] **Step 4: Rerun retry tests** and verify exact event/counter behavior while all existing retry semantics remain unchanged.
- [ ] **Step 5: Write failing circuit tests** for CLOSED→OPEN, OPEN→HALF_OPEN, HALF_OPEN→CLOSED, HALF_OPEN→OPEN, failed state-store writes, and existing concurrent half-open probe behavior without duplicate events.
- [ ] **Step 6: Run the focused circuit tests** and verify the new transition assertions fail before implementation.
- [ ] **Step 7: Add optional instrumentation to circuit transition write points** and emit only after successful persisted transitions.
- [ ] **Step 8: Wire one shared adapter through factory, builder, pipeline, retry executor, and circuit breaker**; preserve existing constructors by adding only optional trailing parameters.
- [ ] **Step 9: Rerun retry, circuit, and pipeline suites**, then Pint/PHPStan on touched files.
- [ ] **Step 10: Commit** as `feat(cloud): observe resilience retries and circuits`.

### Task 4: Record pipeline outcomes, fallback, rejections, and document usage

**Files:**

- Modify: `tusk-cloud/src/Resilience/ResiliencePipeline.php`.
- Modify: `tusk-cloud/tests/Resilience/ResiliencePipelineTest.php`.
- Modify: `tusk-cloud/tests/Resilience/Http/Psr18ResilientClientTest.php`.
- Modify: `tusk-cloud/README.md`.

**Interfaces:**

- Consumes Task 1 `FallbackApplied`/`OperationRejected` and Task 2 `ResilienceInstrumentation`.
- The pipeline records exactly one outcome per `run()`: `success`, `failure`, `fallback_success`, or `fallback_failure`. Duration covers the logical invocation, retries/backoff, and fallback.
- Map only known `CircuitOpenException`, `BulkheadRejectedException`, `BulkheadTimeoutException`, `RateLimitRejectedException`, `ResilienceDeadlineExceededException`, and `OperationCancelledException` to `OperationRejectionReason`; unrelated application failures are not rejection events.
- Emit `FallbackApplied` when fallback starts; preserve the original behavior of returning fallback result or throwing `ResilienceFallbackException` with both failures.

- [ ] **Step 1: Write failing pipeline tests** for exactly-once outcome metrics on success/failure/fallback success/fallback failure; duration including retry/backoff; each recognized rejection reason; no rejection event for arbitrary application exceptions; and event/metric sinks throwing during every final path without changing results or exception identity.
- [ ] **Step 2: Run the focused pipeline tests** and verify expected missing-instrumentation failures.
- [ ] **Step 3: Implement pipeline lifecycle instrumentation** with a `try/catch/finally` structure that emits one final outcome and keeps existing fallback exception behavior intact.
- [ ] **Step 4: Rerun the focused pipeline tests** and verify exact-once accounting and safe failure behavior.
- [ ] **Step 5: Add PSR-18 integration tests** proving a configured factory observes retry and final outcome while the existing final response and request replay behavior are unchanged.
- [ ] **Step 6: Document optional factory wiring, event names, metric names/labels, synchronous dispatch, low-cardinality/privacy constraints, and failure isolation** in `tusk-cloud/README.md`.
- [ ] **Step 7: Run final verification:** full `vendor/bin/phpunit`, `vendor/bin/phpstan analyse tusk-cloud/src tusk-contracts/src --no-progress`, `vendor/bin/pint --test` on touched PHP files, `composer validate --no-check-publish`, and `git diff --check`.
- [ ] **Step 8: Review the complete diff** for unchanged resilience semantics, event transition correctness, exception preservation, cardinality/privacy, and PSR-18 behavior; request independent code review before PR.
- [ ] **Step 9: Commit** as `feat(cloud): instrument resilience outcomes`.

## Self-Review

- **Spec coverage:** Optional sinks and compatibility are wired in Task 3; event contracts in Task 1; sink isolation and metric cardinality in Task 2; retry/circuit emission in Task 3; logical outcomes, rejection mapping, fallback behavior, PSR-18 integration, docs, and full verification in Task 4. Lifecycle ownership and deferred issue scope remain unchanged.
- **Step scan:** Each task uses focused red/green tests; integration, documentation, and final quality gates remain explicit and verifiable.
- **Type consistency:** Task 1 event names and constructor types are consumed consistently by Task 2 adapter and Tasks 3–4. Factory passes one shared `ResilienceInstrumentation` to the builder, retry executor, breaker, and pipeline. Metric outcome/rejection values match the spec.
- **Review focus:** Sink exceptions, retry eligibility, circuit transitions/races, metric cardinality, and fallback/rejection preservation each have named tests in the task that owns the behavior.
- **Proportion:** Four tasks correspond to event contracts, isolation adapter, retry/circuit instrumentation, and pipeline/documentation integration; no exporter, runtime, config binder, or Engine work is included.
