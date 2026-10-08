# Resilience Programmatic Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current best-effort retry/circuit-breaker helpers with a provider-neutral, deterministic resilience core that is safe for persistent PHP workers and can later support PSR-18 middleware, runtime configuration, and compiled attributes.

**Architecture:** Contracts and immutable policy/value objects live behind `tusk-contracts`; concrete clocks, executors, state stores, concurrency control, rate limiting, and the programmatic pipeline live in `tusk-cloud`. The pipeline composes bounded admission, circuit protection, retry, and explicit failure handling without static mutable state or hidden worker-global state.

**Tech Stack:** PHP 8.2+, PHPUnit 10, PHPStan 2.2, Laravel Pint, existing Tusk PSR-4 packages, and the existing `StateStoreInterface` abstraction.

**Spec:** `docs/superpowers/specs/2026-10-06-resilience-core-design.md`

## Global Constraints

- Keep this plan limited to the first two delivery phases in the spec: contracts/primitives and the programmatic execution core.
- Do not add PHP attributes, runtime reflection, PSR-18 middleware, RoadRunner gateway behavior, or external provider adapters in this cycle.
- Preserve provider neutrality: the core must not depend on Guzzle, RoadRunner, OpenTelemetry SDK types, or framework-specific HTTP classes.
- Every waiting boundary must be bounded by a deadline, a bounded queue, or immediate rejection. No unbounded retry, queue, or fallback loop is allowed.
- Retry is opt-in for potentially unsafe operations. The default policy must not silently retry arbitrary failures or non-idempotent work.
- Use injected clocks and state stores. Production code must not use `microtime()`, `usleep()`, static mutable state, or process-global registries for policy state.
- Record one logical operation outcome in the circuit breaker; individual retry attempts must not independently trip the circuit.
- Preserve the existing package boundary: contracts in `tusk-contracts`, implementations in `tusk-cloud`.
- Tests must cover success, failure, deadlines, classification, state transitions, contention, and deterministic timing.

## Review Focus

- Verify that the public API is understandable without Spring, Java, or RoadRunner knowledge.
- Verify that retry, circuit, bulkhead, and rate-limit semantics compose predictably in a persistent worker.
- Check that half-open probes are bounded and that concurrent callers cannot accidentally reopen or bypass a circuit.
- Check that unsafe retries require an explicit opt-in and that final failures preserve the original throwable.
- Check that all time-based tests use a fake/injected clock and cannot flake on CI.
- Check that no task introduces a second overlapping resilience abstraction or revives the old `Retry`/`CircuitBreaker` semantics under a new name.

---

## Task 1: Define provider-neutral resilience contracts and deterministic test infrastructure

**Files:**

- Create `tusk-contracts/src/Cloud/Resilience/ClockInterface.php`.
- Create `tusk-contracts/src/Cloud/Resilience/DeadlineInterface.php`.
- Create `tusk-contracts/src/Cloud/Resilience/OperationContext.php`.
- Create `tusk-contracts/src/Cloud/Resilience/FailureDecision.php` and `FailureClassifierInterface.php`.
- Create `tusk-cloud/src/Resilience/SystemClock.php` and `Testing/FakeClock.php`.
- Update `tusk-cloud/composer.json` only if the package needs explicit development autoloading.
- Add `tusk-cloud/tests/Resilience/ContractAndClockTest.php`.
- Add `tusk-cloud/tests/Support/ResilienceTestCase.php` if shared test helpers are needed.
- Add `tusk-cloud/tests` to the root `phpunit.xml` test suite.

**Public API:**

- `ClockInterface::nowMilliseconds(): int` and `ClockInterface::sleepMilliseconds(int $milliseconds): void`.
- `DeadlineInterface::isExpired(int $nowMilliseconds): bool` and `remainingMilliseconds(int $nowMilliseconds): ?int`.
- `OperationContext::create(string $operation, ?DeadlineInterface $deadline = null, bool $retryAllowed = false, array $metadata = []): self`, plus read-only accessors for operation name, deadline, retry permission, and metadata.
- `FailureClassifierInterface::classify(Throwable $failure, OperationContext $context): FailureDecision`.
- `FailureDecision` exposes whether the failure is retryable and whether it counts as a circuit failure; provide named constructors for retryable and terminal decisions.

**TDD steps:**

- [x] Write tests first for monotonic fake time, deadline expiry/remaining time, immutable operation context, and default terminal classification.
- [x] Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/ContractAndClockTest.php`; confirm the new tests fail because the contracts and implementations do not exist.
- [x] Implement the contracts, `SystemClock`, `FakeClock`, and value objects with strict validation for negative time and invalid operation names.
- [x] Run the focused test again and then `vendor/bin/phpunit tusk-cloud/tests/Resilience/ContractAndClockTest.php`; confirm green.
- [x] Run `vendor/bin/pint --test` on touched PHP files.
- [x] Commit as `feat(resilience): add deterministic resilience contracts`.

## Task 2: Introduce validated policies and backoff strategies

**Files:**

- Create `tusk-cloud/src/Resilience/RetryPolicy.php`.
- Create `tusk-cloud/src/Resilience/BackoffStrategyInterface.php` and concrete fixed, exponential, and decorrelated-jitter strategies.
- Create or replace `tusk-cloud/src/Resilience/CircuitBreakerPolicy.php`.
- Create `tusk-cloud/src/Resilience/BulkheadPolicy.php`.
- Create `tusk-cloud/src/Resilience/RateLimitPolicy.php`.
- Update `tusk-cloud/src/Resilience/State.php` if the existing enum needs stable public state values.
- Add `tusk-cloud/tests/Resilience/PolicyValidationTest.php` and `BackoffStrategyTest.php`.

**Design rules:**

- `RetryPolicy` contains `maxAttempts`, a `BackoffStrategyInterface`, a classifier, and an explicit `allowUnsafeRetries` flag; operation deadlines remain in `OperationContext`. Attempts are total attempts, so `maxAttempts: 1` means no retry.
- Backoff strategies return a non-negative delay capped by policy and accept an injected random source for jitter tests; no strategy may sleep itself.
- `CircuitBreakerPolicy` contains failure threshold, open duration, half-open probe limit, and a bounded rolling/failure counter choice. Defaults are conservative and validated.
- `BulkheadPolicy` contains maximum concurrent operations and maximum queued operations, with queue size defaulting to zero.
- `RateLimitPolicy` contains token capacity, refill rate, and bounded wait behavior. Invalid zero/negative rates and capacities fail during construction.
- Policies are immutable and can be safely shared by multiple pipeline instances.

**TDD steps:**

- [x] Write failing tests for all validation boundaries, attempt-count semantics, fixed/exponential delay caps, deterministic jitter, zero queue defaults, and rate token refill.
- [x] Run the two focused test files and capture the expected red result.
- [x] Implement the immutable policy objects and backoff strategies; keep randomness injectable and keep all sleeping outside the strategies.
- [x] Run the focused tests until green and verify there are no implicit retries in the default policy.
- [x] Run Pint and PHPStan against `tusk-cloud/src/Resilience`.
- [x] Commit as `feat(resilience): add validated resilience policies`.

## Task 3: Implement the deadline-aware retry executor

**Files:**

- Create `tusk-cloud/src/Resilience/RetryExecutor.php`.
- Create `tusk-cloud/src/Resilience/Exception/ResilienceDeadlineExceededException.php` if a dedicated deadline error is required.
- Replace the naive implementation in `tusk-cloud/src/Resilience/Retry.php`; either remove it as an intentional breaking cleanup or make it a small deprecated adapter that delegates to `RetryExecutor` without retaining old behavior.
- Add `tusk-cloud/tests/Resilience/RetryExecutorTest.php`.

**Public API:**

- `RetryExecutor::execute(callable $operation, OperationContext $context, RetryPolicy $policy): mixed`.
- The operation receives the current `OperationContext`; the executor preserves the last original throwable when the operation is terminal or attempts are exhausted.

**Execution rules:**

- Execute once, classify each failure, stop on terminal classification, stop when attempts are exhausted, and stop before a backoff that would exceed the deadline.
- Check the deadline before each attempt and before sleeping. A deadline failure must identify the operation and retain the prior failure as its previous throwable where applicable.
- Respect `OperationContext::retryAllowed`; unsafe work is never retried unless the policy explicitly opts in.
- The executor requests delay from the strategy and delegates waiting to the injected clock, making tests deterministic.

**TDD steps:**

- [x] Write failing tests for success on first attempt, retryable failure then success, terminal failure, max-attempt exhaustion, unsafe-operation refusal, deadline during backoff, and original exception preservation.
- [x] Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/RetryExecutorTest.php`; confirm red.
- [x] Implement the executor and the deliberate compatibility/removal decision for `Retry.php`.
- [x] Run the focused tests, then the complete resilience test directory; confirm green.
- [x] Run Pint and PHPStan; commit as `feat(resilience): add deadline-aware retry execution`.

## Task 4: Rebuild the circuit breaker with bounded half-open probes

**Files:**

- Update `tusk-cloud/src/Resilience/CircuitBreaker.php`.
- Update `tusk-cloud/src/Resilience/CircuitBreakerInterface.php`.
- Update `tusk-cloud/src/Resilience/InMemoryStateStore.php` to retain provider-neutral state snapshots.
- Update `tusk-cloud/src/Resilience/State.php` and `tusk-cloud/src/Resilience/Exception/CircuitOpenException.php` as needed.
- Update `tusk-contracts/src/Cloud/Resilience/StateStoreInterface.php` only if a typed snapshot contract is required; preserve the provider-neutral store boundary.
- Add `tusk-cloud/tests/Resilience/CircuitBreakerTest.php`.

**Public API:**

- `CircuitBreakerInterface::execute(callable $operation, OperationContext $context, CircuitBreakerPolicy $policy): mixed`.
- `CircuitBreakerInterface::state(): State` and a diagnostic snapshot accessor suitable for metrics/health integration later.

**State-machine rules:**

- Closed calls execute normally and count only classified logical-operation failures.
- Open calls fail fast with `CircuitOpenException` until the injected clock reaches the reset time.
- Transition to half-open admits at most `halfOpenProbeLimit` calls; all other callers fail fast rather than queueing indefinitely.
- A successful probe closes and resets the breaker; a failed probe reopens it and resets the open timer.
- State writes are namespaced by breaker name and use the injected store; no static state is permitted.
- The store representation must be safe to serialize and must tolerate a missing or expired record as closed.

**TDD steps:**

- [x] Write failing state-machine tests for closed success, threshold opening, fail-fast open, clock-driven half-open transition, one successful probe, failed probe reopening, and concurrent probe rejection.
- [x] Run the focused circuit test and confirm red.
- [x] Implement the state machine and atomic-enough in-memory admission behavior for the current process; document that distributed atomicity belongs to a future store adapter.
- [x] Run focused tests plus retry/circuit interaction tests; confirm green.
- [x] Run Pint and PHPStan; commit as `feat(resilience): bound circuit breaker half-open probes`.

## Task 5: Add bounded bulkhead and token-bucket rate limiting

**Files:**

- Create `tusk-cloud/src/Resilience/Bulkhead.php` and its rejection exception.
- Create `tusk-cloud/src/Resilience/RateLimiter.php` and its rejection/timeout exception.
- Create or reuse a small injected random/source abstraction only if required by deterministic token timing.
- Add `tusk-cloud/tests/Resilience/BulkheadTest.php` and `RateLimiterTest.php`.

**Public API:**

- `Bulkhead::run(callable $operation, OperationContext $context, BulkheadPolicy $policy): mixed`.
- `RateLimiter::acquire(OperationContext $context, RateLimitPolicy $policy): void` and a release-free token-bucket implementation.

**Execution rules:**

- Bulkhead admission is bounded by concurrency and queue limits. With queue size zero, excess callers fail immediately.
- A queued caller can wait only until its operation deadline; timeout and rejection are distinct exceptions.
- Every admitted bulkhead slot is released in `finally`, including exceptions and deadline failures.
- Rate limiting uses deterministic token refill from the injected clock, never sleeps beyond the context deadline, and never creates an unbounded waiter list.
- No policy silently drops work or invokes a fallback; exceptions propagate to the pipeline.

**TDD steps:**

- [x] Write failing tests for immediate bulkhead rejection, bounded queue admission, deadline expiration while queued, release on throwable, token consumption, refill, and bounded rate-limit wait.
- [x] Run the focused tests and confirm red.
- [x] Implement the bounded primitives with explicit exceptions and `finally` cleanup.
- [x] Run focused tests, the full resilience suite, Pint, and PHPStan; commit as `feat(resilience): add bounded bulkheads and rate limiting`.

## Task 6: Compose the programmatic resilience pipeline

**Files:**

- Create `tusk-cloud/src/Resilience/ResiliencePipeline.php`.
- Create `tusk-cloud/src/Resilience/ResiliencePipelineBuilder.php` or `ResiliencePipelineFactory.php`.
- Create `tusk-cloud/src/Resilience/FailureHandlerInterface.php` only if an explicit fallback abstraction is needed.
- Add `tusk-cloud/tests/Resilience/ResiliencePipelineTest.php`.
- Update `tusk-cloud/README.md` with the public programmatic API and safety rules.

**Public API:**

- `ResiliencePipelineFactory::pipeline(string $name): ResiliencePipelineBuilder`.
- Builder methods `withRetry(RetryPolicy)`, `withCircuitBreaker(CircuitBreakerPolicy)`, `withBulkhead(BulkheadPolicy)`, `withRateLimit(RateLimitPolicy)`, and `withFallback(callable)` return immutable builders.
- `ResiliencePipeline::run(callable $operation, ?OperationContext $context = null): mixed`.

**Composition rules:**

- Admission order is bulkhead, circuit preflight, then retry; the rate limiter runs before each outbound attempt so retries cannot bypass the configured outbound budget.
- The circuit observes the final logical outcome after retry, not every attempt.
- Bulkhead resources are held only for the logical operation and are always released.
- A fallback is explicit, receives the final throwable and context, and is never invoked for successful results; without one, the final throwable propagates unchanged.
- The pipeline has no framework globals and can be constructed per application/service name.

**TDD steps:**

- [x] Write failing tests for fluent configuration, operation-context propagation, composition order, final-outcome circuit accounting, rate limiting per attempt, bulkhead cleanup, explicit fallback, and original exception propagation.
- [x] Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/ResiliencePipelineTest.php`; confirm red.
- [x] Implement the builder/factory and pipeline using the previously tested primitives; do not duplicate policy logic in the pipeline.
- [x] Run the complete resilience suite and confirm green.
- [x] Update the README with a minimal idiomatic example and a warning that attributes/HTTP integration are future phases.
- [x] Run Pint and PHPStan; commit as `feat(resilience): add programmatic resilience pipelines`.

## Task 7: Integrate quality gates and verify the first delivery phase

**Files:**

- Update `tusk-cloud/README.md` and, if useful, the root `README.md` with the resilience-core status and roadmap.
- Update package metadata only where required by the new public classes.
- Add or update `tusk-cloud/tests/Resilience/ResilienceArchitectureTest.php` to assert no resilience class uses static mutable state or direct wall-clock/sleep calls.
- Update `docs/superpowers/specs/2026-10-06-resilience-core-design.md` with implementation notes only if behavior was intentionally refined.

**Verification steps:**

- [x] Run `vendor/bin/phpunit tusk-cloud/tests/Resilience`.
- [x] Run the root `vendor/bin/phpunit` suite (one local failure from the ignored `.worktrees/hardening` fixture is documented in the report).
- [x] Run `vendor/bin/phpstan analyse tusk-cloud/src tusk-contracts/src --no-progress` using the repository's configured baseline/options.
- [x] Run `vendor/bin/pint --test` on all touched PHP files.
- [x] Run `git diff --check` and inspect the final diff for accidental generated files, static state, unbounded waits, or provider-specific imports.
- [x] Run the existing CI-equivalent commands before opening the pull request.
- [x] Open the pull request with the repository's required Context, Scope, Design, Verification, Compatibility, Risks, Reviewer guide, Checklist, and References sections.
- [x] Request a code review using `superpowers:requesting-code-review`; address findings before merging.
- [x] Update issue #10 with the phase evidence and create/link follow-up issue #29; do not close #10 until its full acceptance criteria, including HTTP integration and runtime observability, are complete.

## Follow-up plans intentionally excluded from this cycle

- PSR-18 middleware and HTTP status classification.
- Tusk Events, metrics, tracing, health/readiness, and Engine diagnostics integration.
- Runtime configuration and boot-time validation.
- Compiled PHP attributes/declarative metadata with no runtime reflection.
- Provider adapters or distributed state stores.

