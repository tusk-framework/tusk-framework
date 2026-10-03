# Observability and Persistent Worker Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add provider-neutral telemetry, OpenTelemetry export, and safe persistent-worker diagnostics to Tusk without changing existing lifecycle or runtime behavior when observability is disabled.

**Architecture:** Keep telemetry contracts in `tusk-contracts` and implement the runtime collector/provider boundary in `tusk-runtime`. The existing `LifecycleManager` owns event ordering and delegates instrumentation to an injected observer; a no-op observer is the default. OpenTelemetry mapping lives behind a dedicated runtime adapter and is configured once during startup. Diagnostics are collected in the PHP worker, exposed through one immutable snapshot model, and rendered by a CLI command; the Tusk Engine can consume the same model in a later transport change.

**Tech Stack:** PHP 8.2+, PHPUnit 10, PHPStan 2, PSR-3, OpenTelemetry PHP API/SDK, OTLP exporter, PSR HTTP client adapter, Symfony Console, existing RoadRunner lifecycle and module contracts.

**Spec:** `docs/superpowers/specs/2026-10-03-observability-worker-diagnostics-design.md`

## Global Constraints

- `tusk-contracts` must not import OpenTelemetry SDK classes, exporters, RoadRunner types, or vendor-specific resource objects.
- Telemetry is disabled and no-op by default.
- Configuration is parsed once during application startup; invalid enabled configuration fails before the runtime starts.
- Telemetry/exporter failures must never replace an application exception or turn a successful request into a failed request.
- Diagnostics must not expose authorization headers, cookies, request bodies, uploads, secrets, tokens, or arbitrary exception traces.
- Lifecycle instrumentation must work for application, worker, request, and job boundaries without requiring a live RoadRunner process.
- Existing `OnStart`, `OnShutdown`, lifecycle transitions, runtime adapters, and null bootstrap behavior remain compatible.
- RoadRunner remains responsible for worker supervision, recycling, IPC, and plugin metrics.
- OpenTelemetry integration must use the API/SDK/exporter boundary documented by the official OpenTelemetry PHP project; OTLP HTTP is the first transport and gRPC export is not part of this delivery.

## Review Focus

- A request handler throws while telemetry shutdown also fails: the original handler exception must be rethrown and the telemetry failure only logged/countable — Task 4.
- OTLP is enabled with an invalid endpoint or sample ratio: startup must fail before the worker loop — Task 3.
- A diagnostic snapshot contains a token, cookie, body, upload, or stack trace: serialization must exclude it — Task 2.
- Lifecycle start/stop is called repeatedly or teardown is exceptional: spans must close exactly once and counters must remain consistent — Task 4.
- The CLI is run outside a persistent worker: it must render the same snapshot schema with an explicit local/standalone state, never claim remote worker health — Task 5.

---

### Task 1: Define provider-neutral observability contracts and snapshots

**Files:**
- Create: `tusk-contracts/src/Observability/SpanInterface.php`
- Create: `tusk-contracts/src/Observability/TelemetryProviderInterface.php`
- Create: `tusk-contracts/src/Observability/WorkerDiagnosticsInterface.php`
- Create: `tusk-contracts/src/Observability/WorkerDiagnosticsSnapshot.php`
- Test: `tusk-runtime/tests/Observability/ObservabilityContractTest.php`

**Interfaces:**
- `SpanInterface::setAttribute(string $name, string|int|float|bool|null $value): void`.
- `SpanInterface::recordException(Throwable $exception, array $attributes = []): void`.
- `SpanInterface::setStatus(string $status, ?string $description = null): void`.
- `SpanInterface::end(?float $endTime = null): void`.
- `TelemetryProviderInterface::startSpan(string $name, array $attributes = []): SpanInterface`.
- `TelemetryProviderInterface::increment(string $name, int|float $value = 1, array $attributes = []): void`.
- `TelemetryProviderInterface::observe(string $name, float $value, array $attributes = []): void`.
- `TelemetryProviderInterface::flush(): void` and `shutdown(): void`.
- `WorkerDiagnosticsInterface::snapshot(): WorkerDiagnosticsSnapshot`.
- `WorkerDiagnosticsSnapshot` is an immutable value object with worker ID, runtime, lifecycle state, start time, uptime, request/job counts, in-flight work, duration aggregates, memory values, scope reset/anomaly counts, telemetry failures, and redacted last-failure metadata. It exposes `toArray(): array` and `JsonSerializable` output using stable scalar keys.

- [ ] **Step 1: Write failing contract tests**

  Assert the exact method signatures, immutable snapshot construction, stable `toArray()` keys, JSON serialization, and rejection of non-scalar diagnostic values.

- [ ] **Step 2: Run the focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/ObservabilityContractTest.php`

  Expected: FAIL because the contracts and snapshot do not exist.

- [ ] **Step 3: Implement the contracts and snapshot**

  Keep the contracts dependency-free apart from PHP standard types and `Throwable`. The snapshot must normalize timestamps to ISO-8601 strings and never accept arbitrary payload arrays.

- [ ] **Step 4: Run focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/ObservabilityContractTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-contracts/src/Observability tusk-runtime/tests/Observability/ObservabilityContractTest.php
  git commit -m "feat: define observability contracts"
  ```

### Task 2: Implement no-op telemetry and worker diagnostics collection

**Files:**
- Create: `tusk-runtime/src/Observability/NoopSpan.php`
- Create: `tusk-runtime/src/Observability/NoopTelemetryProvider.php`
- Create: `tusk-runtime/src/Observability/WorkerDiagnosticsCollector.php`
- Create: `tusk-runtime/src/Observability/DiagnosticsClockInterface.php`
- Create: `tusk-runtime/src/Observability/SystemDiagnosticsClock.php`
- Test: `tusk-runtime/tests/Observability/NoopTelemetryProviderTest.php`
- Test: `tusk-runtime/tests/Observability/WorkerDiagnosticsCollectorTest.php`

**Interfaces:**
- `WorkerDiagnosticsCollector` implements `WorkerDiagnosticsInterface` and records only explicit lifecycle values: `applicationStarted()`, `workerStarted(string $workerId, string $runtime)`, `requestStarted()`, `requestFinished(?int $status, ?Throwable $exception, float $durationSeconds)`, `jobStarted(string $name)`, `jobFinished(bool $success, ?Throwable $exception, float $durationSeconds)`, `requestScopeReset(bool $anomaly = false)`, `telemetryFailure(Throwable $exception)`, `workerStopped()`, and `applicationStopped()`.
- `DiagnosticsClockInterface::now(): DateTimeImmutable` and `monotonicSeconds(): float` make durations deterministic in tests.
- `NoopTelemetryProvider` always returns a no-op span and ignores metrics, flush, and shutdown without throwing.

- [ ] **Step 1: Write failing collector and no-op tests**

  Assert request/job counters, in-flight counts, max/total duration, memory values, state transitions, telemetry failure counts, and safe redaction of last-failure category/message. Assert the no-op provider does not throw for every contract method.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/NoopTelemetryProviderTest.php tusk-runtime/tests/Observability/WorkerDiagnosticsCollectorTest.php`

  Expected: FAIL because the implementations do not exist.

- [ ] **Step 3: Implement the collector and no-op provider**

  Use `memory_get_usage(true)` and `memory_get_peak_usage(true)` at snapshot time. Preserve only a bounded, category-based last failure message; strip newlines and never store exception traces, request data, or headers. Make invalid lifecycle calls idempotent where the existing lifecycle manager is idempotent.

- [ ] **Step 4: Run focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/NoopTelemetryProviderTest.php tusk-runtime/tests/Observability/WorkerDiagnosticsCollectorTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-runtime/src/Observability tusk-runtime/tests/Observability
  git commit -m "feat: add persistent worker diagnostics"
  ```

### Task 3: Add validated observability configuration and OpenTelemetry bridge

**Files:**
- Create: `tusk-runtime/src/Observability/ObservabilityConfiguration.php`
- Create: `tusk-runtime/src/Observability/ObservabilityProviderFactory.php`
- Create: `tusk-runtime/src/Modules/RuntimeObservabilityModule.php`
- Create: `tusk-runtime/src/Observability/OpenTelemetry/OpenTelemetrySpan.php`
- Create: `tusk-runtime/src/Observability/OpenTelemetry/OpenTelemetryProvider.php`
- Create: `tusk-runtime/tests/Observability/ObservabilityConfigurationTest.php`
- Create: `tusk-runtime/tests/Observability/OpenTelemetry/OpenTelemetryProviderTest.php`
- Modify: `tusk-runtime/composer.json`
- Modify: `composer.json`
- Modify: `tusk-runtime/src/RuntimeConfiguration.php`
- Modify: `tusk-runtime/src/RuntimeModuleFactory.php`

**Interfaces:**
- `ObservabilityConfiguration::fromArray(array $config): self` validates `enabled`, `service_name`, `exporter`, `otlp.endpoint`, `sample_ratio`, safe resource attributes, and diagnostics enablement. `sample_ratio` must be between `0.0` and `1.0`; `otlp` requires a non-empty absolute HTTP(S) endpoint.
- `ObservabilityProviderFactory::create(ObservabilityConfiguration $configuration): TelemetryProviderInterface` returns `NoopTelemetryProvider` for disabled/`none` and `OpenTelemetryProvider` for enabled `otlp`.
- `RuntimeConfiguration::observability(): ObservabilityConfiguration` preserves current defaults when the `observability` key is absent.
- `RuntimeObservabilityModule` is always added by `RuntimeModuleFactory`, registers the selected `TelemetryProviderInterface` and `WorkerDiagnosticsInterface` in the container, and keeps the default path no-op.
- `OpenTelemetryProvider` maps Tusk span/metric calls to the OpenTelemetry API/SDK and OTLP HTTP exporter, catches exporter failures at flush/shutdown boundaries, and exposes them to the diagnostics collector through a callback or injected failure recorder.

- [ ] **Step 1: Write failing configuration and bridge tests**

  Cover disabled defaults, valid OTLP configuration, invalid exporter names, missing endpoint, non-HTTP endpoint, invalid sample ratios, safe resource attributes, provider selection, span status/exception mapping, and exporter failure isolation using test doubles around the OpenTelemetry API boundary.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/ObservabilityConfigurationTest.php tusk-runtime/tests/Observability/OpenTelemetry/OpenTelemetryProviderTest.php`

  Expected: FAIL because configuration and provider implementations do not exist.

- [ ] **Step 3: Add dependencies and implement the bridge**

  Add `open-telemetry/api`, `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `guzzlehttp/guzzle`, and `php-http/guzzle7-adapter` with compatible PHP 8.2 constraints to the root and runtime package manifests. Keep OpenTelemetry imports confined to `tusk-runtime/src/Observability/OpenTelemetry/`. Use OTLP HTTP first; do not add an `ext-grpc` requirement.

- [ ] **Step 4: Run focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/ObservabilityConfigurationTest.php tusk-runtime/tests/Observability/OpenTelemetry/OpenTelemetryProviderTest.php`

  Expected: PASS with zero failures and no external collector required.

- [ ] **Step 5: Commit**

  ```bash
  git add composer.json composer.lock tusk-runtime/composer.json tusk-runtime/src/RuntimeConfiguration.php tusk-runtime/src/RuntimeModuleFactory.php tusk-runtime/src/Observability
  git commit -m "feat: integrate OpenTelemetry provider"
  ```

### Task 4: Instrument lifecycle and job boundaries

**Files:**
- Create: `tusk-runtime/src/Observability/RuntimeObservability.php`
- Create: `tusk-runtime/src/Observability/LifecycleObserverInterface.php`
- Create: `tusk-runtime/tests/Observability/RuntimeObservabilityTest.php`
- Modify: `tusk-runtime/src/LifecycleManager.php`
- Modify: `tusk-runtime/src/Kernel.php`
- Modify: `tusk-cli/src/Commands/QueueWorkerCommand.php`
- Modify: `tusk-runtime/tests/LifecycleManagerTest.php`
- Modify: `tusk-runtime/tests/KernelTest.php`

**Interfaces:**
- `LifecycleObserverInterface` defines `applicationStarted(): void`, `workerStarted(): void`, `requestStarted(mixed $request = null): void`, `requestFinished(mixed $response = null, ?Throwable $exception = null): void`, `workerStopped(): void`, and `applicationStopped(): void`.
- `RuntimeObservability` implements `LifecycleObserverInterface`, composes `TelemetryProviderInterface` and `WorkerDiagnosticsCollector`, exposes `diagnostics(): WorkerDiagnosticsInterface`, and provides `jobStarted(string $name, ?string $id = null): void` plus `jobFinished(bool $success, ?Throwable $exception = null): void`.
- `RuntimeObservability` starts and closes exactly one active span per lifecycle boundary and records response status when the handler returns a PSR-7 response. It records exceptions without changing thrown values.
- `LifecycleManager` accepts an optional observer after existing constructor arguments, preserving current callers. It calls observer methods in the same ordered transitions and in `finally` paths; observer failures are logged and do not replace handler failures.
- `Kernel` accepts an optional `RuntimeObservability` after the existing module argument, preserving current callers. When omitted, it resolves the registered observability service if present and otherwise uses the existing lifecycle behavior. It passes the observer into the lifecycle manager without changing adapter responsibilities.
- `QueueWorkerCommand` surrounds job execution with job instrumentation while preserving existing queue acknowledgement/failure behavior.

- [ ] **Step 1: Write failing lifecycle instrumentation tests**

  Assert application/worker/request spans, request status and duration, failed handler recording, exactly-once closure on repeated stop, job success/failure spans, diagnostics counter updates, and original-exception precedence when observer cleanup fails.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability/RuntimeObservabilityTest.php tusk-runtime/tests/LifecycleManagerTest.php tusk-runtime/tests/KernelTest.php`

  Expected: FAIL because lifecycle observers are not wired into the manager/kernel.

- [ ] **Step 3: Implement observer wiring and failure isolation**

  Keep lifecycle ordering owned by `LifecycleManager`; do not add request cleanup to an observer. A request-end observer failure is logged/countable, while the handler exception remains authoritative. Use the existing response/request types only at the runtime boundary.

- [ ] **Step 4: Run focused and existing runtime tests**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Observability tusk-runtime/tests/LifecycleManagerTest.php tusk-runtime/tests/KernelTest.php tusk-runtime/tests/Adapters`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-runtime/src tusk-runtime/tests tusk-cli/src/Commands/QueueWorkerCommand.php
  git commit -m "feat: instrument persistent lifecycle"
  ```

### Task 5: Add the diagnostics CLI command and stable rendering

**Files:**
- Create: `tusk-cli/src/Commands/RuntimeDiagnosticsCommand.php`
- Create: `tusk-cli/tests/Commands/RuntimeDiagnosticsCommandTest.php`
- Modify: `tusk-cli/composer.json`
- Modify: `README.md`
- Modify: `tusk-runtime/README.md`

**Interfaces:**
- Register `runtime:diagnostics` with `#[AsCommand('runtime:diagnostics', 'Show persistent runtime diagnostics')]`.
- Support `--json`; default output is concise human-readable operational text.
- Inject `WorkerDiagnosticsInterface`; both renderers consume only `WorkerDiagnosticsSnapshot::toArray()`.
- When invoked in a standalone CLI process, render `lifecycle_state=standalone` and clearly state that it is a local snapshot, never a remote worker health claim.

- [ ] **Step 1: Write failing command tests**

  Assert command registration metadata, human output, JSON output, stable key order, and explicit standalone state. Use a fixed snapshot fake; do not assert on Symfony internals.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-cli/tests/Commands/RuntimeDiagnosticsCommandTest.php`

  Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command and documentation**

  Keep output free of secrets and payload data. Document that remote worker diagnostics require a future Engine control-plane request using the same snapshot schema.

- [ ] **Step 4: Run focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-cli/tests/Commands/RuntimeDiagnosticsCommandTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-cli/src/Commands/RuntimeDiagnosticsCommand.php tusk-cli/tests/Commands/RuntimeDiagnosticsCommandTest.php tusk-cli/composer.json README.md tusk-runtime/README.md
  git commit -m "feat: add runtime diagnostics command"
  ```

### Task 6: Full verification, documentation, and issue handoff

**Files:**
- Modify: `tusk-runtime/SPEC.md`
- Modify: `tusk-contracts/README.md`
- Modify: `tusk-runtime/README.md`
- Modify: `README.md`
- Test: all existing repository suites

- [ ] **Step 1: Run the complete test suite**

  Run: `vendor/bin/phpunit`

  Expected: all tests pass; record skipped tests and environment limitations without presenting them as green coverage.

- [ ] **Step 2: Run static analysis, formatting, and manifest validation**

  Run: `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test` on touched PHP files, `composer validate --strict`, and `git diff --check`.

  Expected: PHPStan and Composer validation pass; any pre-existing formatter baseline failures are isolated and documented by file.

- [ ] **Step 3: Review dependency and boundary hygiene**

  Confirm `rg -n "OpenTelemetry\\\\" tusk-contracts` returns no matches, the default runtime config still selects no-op telemetry, and no diagnostic serializer includes headers, cookies, body, uploads, secrets, tokens, or traces.

- [ ] **Step 4: Update issue #15 and link the implementation**

  Record the verification commands, the snapshot/CLI limitation that remote workers require Engine transport, and the resulting PR link in the GitHub issue. Do not close the issue until all acceptance criteria are implemented and CI is green.

- [ ] **Step 5: Commit documentation/status updates**

  ```bash
  git add README.md tusk-contracts/README.md tusk-runtime/README.md tusk-runtime/SPEC.md
  git commit -m "docs: document observability and worker diagnostics"
  ```
