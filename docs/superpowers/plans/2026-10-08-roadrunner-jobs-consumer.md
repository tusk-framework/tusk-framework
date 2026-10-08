# RoadRunner Jobs Consumer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver named, provider-neutral Tusk jobs on the Engine-managed RoadRunner Jobs runtime, prove dispatch/consumption/retry/shutdown end to end, then remove the obsolete database queue consumer.

**Architecture:** RoadRunner owns queue transport, pipelines, worker pools, and process supervision. Framework owns named handler registration, immutable job context, per-delivery lifecycle/scope, and bounded retry decisions; Engine validates `tusk.json` and projects it into generated RoadRunner configuration. Ship in three gated PRs: Framework consumer, Engine configuration/integration, then legacy removal only after the Engine smoke test passes.

**Tech Stack:** PHP 8.3+, Composer, PHPUnit, PHPStan, RoadRunner v3 and `spiral/roadrunner-jobs`; Go 1.23+, YAML projection and Go tests; PowerShell skeleton smoke test run on Linux for Unix signal/process assertions.

**Spec:** `docs/superpowers/specs/2026-10-08-roadrunner-jobs-consumer-design.md`

## Global Constraints

- RoadRunner owns transport, delivery, worker pools, and process supervision; Tusk must not add a queue driver, polling loop, or second process supervisor.
- Use named handlers registered by Tusk; never instantiate a PHP class named by a message.
- Job payloads are explicit JSON strings; do not serialize or hydrate arbitrary PHP objects.
- Delivery is at-least-once; handlers must be idempotent, ack occurs only after success, and retry is bounded.
- Malformed payloads and unknown job names are non-retryable by default; do not silently ack them or endlessly requeue them.
- Do not claim a universal dead-letter queue; document driver-specific failure retention.
- Reset job-scoped state and finish job lifecycle in `finally`, including handler, ack, retry, and cleanup failures.
- Keep HTTP behavior unchanged; `RR_MODE=http` uses request lifecycle, `RR_MODE=jobs` uses job lifecycle, and other modes fail with an actionable error.
- Engine preserves secret environment placeholders in generated config and never expands or logs secret values.
- Do not remove `QueueWorkerCommand`, `Tusk\Events\Queue\QueueInterface`, or `DatabaseQueue` until the Engine-managed smoke test passes dispatch, consumption, ack, retry, and shutdown.
- Deliver every repository change via PR; sync that repository's local `main` from `origin/main` and verify a clean baseline before each implementation branch. Never use `codex/` as a branch prefix.

## Review Focus

1. **Duplicate, blank, or malformed registered handler names** must fail at boot without ambiguous dispatch; cover in Task 1 registry/compiler tests.
2. **Unknown handler names and invalid JSON payloads** must be classified non-retryable, never acked as success or endlessly requeued; cover in Task 3 processor tests.
3. **Handler exception, retry exhaustion, and retry transport failure** must preserve the original failure, obey the configured bound, and avoid a success ack; cover in Task 3 tests.
4. **Scope reset, job-end hooks, or observability failure during cleanup** must not leak state or replace the primary handler/consumer exception; cover in Task 2 lifecycle tests.
5. **Missing pipeline, unsafe interpolation, and absent Jobs configuration** must respectively fail safely, redact values, and preserve current HTTP-only output; cover in Task 5 Engine config/projection tests.

---

## File Map and Public Interfaces

Framework API decisions for this implementation:

- Create `Tusk\Contracts\Runtime\Jobs\JobHandlerInterface::handle(JobContext $job): void`.
- Create immutable `JobContext` exposing `id(): string`, `queue(): string`, `name(): string`, `payload(): string`, `headers(): array` (`array<string,string>`), and `jsonPayload(): array` (`array<string,mixed>`); require a JSON object and decode with `JSON_THROW_ON_ERROR`, translating parse/type errors to a clear non-retryable payload exception.
- Create class attribute `Tusk\Contracts\Attributes\AsJob(string $name)`. A class with this attribute is registered in the container with `job` scope; its class must implement `JobHandlerInterface`.
- Create a compiled `JobHandlerRegistry` keyed by stable job name. `ApplicationBuilder::withJobs(string ...$directories): self` selects scan roots; duplicate/invalid names fail application creation.
- Expand `JobTaskInterface` to expose `message(): QueueMessageInterface`, `acknowledge(): void`, `retry(?int $delaySeconds = null): void`, and `fail(string $reason): void`. The RoadRunner adapter maps message metadata and these operations to the SDK task API; only bounded, sanitized reasons are sent to transport.
- Framework retry config uses `runtime.jobs.retry.max_attempts` (positive integer, default `3`) and `runtime.jobs.retry.delay_seconds` (non-negative integer, default `1`). The first delivery is attempt 1; Tusk stores the next attempt in a reserved RoadRunner task header, and once the configured attempts are exhausted it calls `fail` without requeue.
- Add `LifecycleEvent::JOB_START` / `JOB_END`, `OnJobStart` / `OnJobEnd` attributes, and observer callbacks only if required by the existing observer contract; no job is routed through request hooks.
- `RR_MODE` is read by the Framework runtime mode selector; absent means `http` for backward compatibility. Only `http` and `jobs` are accepted.

Engine API decisions:

- Add `Config.Jobs JobsConfig` serialized as JSON key `jobs`; `JobsConfig` contains `Consume []string` and `Pipelines map[string]JobPipelineConfig`; each pipeline has `Driver string` and `Config map[string]any`.
- RoadRunner projection emits `jobs.consume` and `jobs.pipelines`, retaining the existing HTTP section and defaults. Jobs absent means no Jobs section and unchanged HTTP behavior.
- Require every consumed pipeline to be declared, unique valid names, non-empty driver, and JSON-compatible driver config. Preserve `${NAME}` and `${NAME:-DEFAULT}` references without expansion; reject malformed/unsafe references and redact config values in errors.
- The integration smoke app dispatches JSON through the existing producer API to an Engine-configured in-memory pipeline, records a handler side effect, forces one bounded retry, confirms the successful second attempt, and verifies graceful process shutdown.

## PR and Branch Sequence

1. **Framework PR:** contract, registry, lifecycle, RoadRunner Jobs worker mode, skeleton, unit/integration tests, and migration documentation. Keep legacy queue and command.
2. **Engine PR:** typed Jobs configuration, deterministic RoadRunner projection, validation/redaction, and Engine-managed end-to-end smoke test against the merged Framework commit.
3. **Framework cleanup PR:** only after PRs 1 and 2 are merged and the smoke is green; remove the old command/database queue and update docs/dependencies. No implementation task may skip this gate.

Each PR gets focused tests, full relevant CI, a structured description (goal, implementation, validation, risk/rollback), and review before merge.

## Phase 1 — Framework Consumer PR

### Task 1: Contracts, JSON context, and handler registry

**Files:**
- Create: `tusk-contracts/src/Attributes/AsJob.php`
- Create: `tusk-contracts/src/Runtime/Jobs/JobHandlerInterface.php`
- Create: `tusk-contracts/src/Runtime/Jobs/JobContext.php`
- Create: `tusk-contracts/src/Runtime/Jobs/JobPayloadException.php`
- Create: `tusk-runtime/src/Jobs/JobHandlerRegistry.php`
- Create: `tusk-runtime/src/Jobs/JobHandlerScanner.php`
- Modify: `tusk-contracts/src/Runtime/Capabilities/JobTaskInterface.php`
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php`
- Test: `tusk-runtime/tests/Jobs/JobContextTest.php`, `tusk-runtime/tests/Jobs/JobHandlerScannerTest.php`, and `tusk-core/tests/Foundation/ApplicationBuilderTest.php`

**Interfaces:**
- Produces the public contracts and registry specified in “File Map and Public Interfaces”.
- `JobHandlerRegistry::handlerClass(string $name): string` returns only a registered class and throws a typed unknown-job exception otherwise.
- `ApplicationBuilder::withJobs(string ...$directories): self` records scan roots; `create()` compiles the registry and binds it to the container before returning the application.

- [ ] **Step 1: Write failing context and scanner tests** for valid JSON object access, invalid JSON, JSON scalar/list rejection, stable-name trimming/validation, duplicate names, non-handler class rejection, and valid handler resolution.
- [ ] **Step 2: Run the focused tests** with `composer test -- tusk-runtime/tests/Jobs/JobContextTest.php tusk-runtime/tests/Jobs/JobHandlerScannerTest.php`; confirm failures are due to missing API/behavior.
- [ ] **Step 3: Implement the contracts, typed exceptions, scanner, registry, and builder registration** at the paths above; register discovered handlers with `job` scope and never trust a class name from task data.
- [ ] **Step 4: Run focused tests and container tests** with `composer test -- tusk-runtime/tests/Jobs tusk-core/tests/Foundation/ApplicationBuilderTest.php tusk-core/tests/Container`; expect all PASS.
- [ ] **Step 5: Commit** as `feat: add named job handler registry`.

### Task 2: Job lifecycle, hooks, scope reset, and observability

**Files:**
- Modify: `tusk-contracts/src/Runtime/LifecycleEvent.php`
- Create: `tusk-contracts/src/Attributes/OnJobStart.php`, `tusk-contracts/src/Attributes/OnJobEnd.php`
- Modify: `tusk-core/src/Container/Container.php`, `tusk-core/src/Container/ServiceScanner.php`, `tusk-core/src/Container/ContainerCompiler.php`
- Modify: `tusk-runtime/src/LifecycleManager.php`, `tusk-runtime/src/Observability/LifecycleObserverInterface.php`, `tusk-runtime/src/Observability/RuntimeObservability.php`
- Test: `tusk-core/tests/Container/LifecycleHooksTest.php`, `tusk-core/tests/Container/ContainerCompilerTest.php`, `tusk-runtime/tests/LifecycleManagerTest.php`, `tusk-runtime/tests/Observability/RuntimeObservabilityTest.php`

**Interfaces:**
- Consumes `JobContext` and the task/handler contracts from Task 1.
- Produces job-start/end hooks and a per-job lifecycle boundary that resets `job` scope in `finally`; HTTP request lifecycle stays unchanged.

- [ ] **Step 1: Add failing tests** proving job hooks execute in order, `job`-scoped services are new for each delivery in dynamic and compiled containers, reset occurs after a handler exception, and existing request hooks remain unchanged.
- [ ] **Step 2: Run those focused tests** with `composer test -- tusk-core/tests/Container tusk-runtime/tests/LifecycleManagerTest.php`; confirm the new assertions fail on current behavior.
- [ ] **Step 3: Implement job events and hook discovery** consistently in dynamic container, scanner, and compiled container; make job cleanup preserve the first failure while reporting cleanup failures secondarily.
- [ ] **Step 4: Add observability assertions** for one start and one finish per delivery, bounded-cardinality job name only, no raw payload/header labels, then run the focused suite; expect PASS.
- [ ] **Step 5: Commit** as `feat: add isolated job lifecycle`.

### Task 3: RoadRunner task adapter, processor, and retry semantics

**Files:**
- Modify: `tusk-runtime/src/RoadRunner/RoadRunnerJobTask.php`
- Modify: `tusk-runtime/src/RoadRunner/RoadRunnerJobsModule.php`
- Create: `tusk-runtime/src/Jobs/JobProcessor.php`
- Create: `tusk-runtime/src/Jobs/JobRetryConfiguration.php`
- Modify: `tusk-runtime/src/RuntimeConfiguration.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerJobTaskTest.php`, `tusk-runtime/tests/RoadRunner/RoadRunnerJobsModuleTest.php`, and new `tusk-runtime/tests/Jobs/JobProcessorTest.php`

**Interfaces:**
- Consumes `JobContext`, `JobHandlerRegistry`, and lifecycle from Tasks 1–2.
- `JobProcessor::process(JobTaskInterface $task): void` resolves the named handler, creates a context, invokes it inside job lifecycle/scope, acks once after success, retries bounded handler exceptions, and fails poison/exhausted tasks without requeue.
- RoadRunner task adapter maps SDK `ReceivedTaskInterface` metadata, ack, delay/requeue, and nack operations to the Tusk task contract.

- [ ] **Step 1: Write failing adapter/processor tests** for task metadata conversion, ack only on success, first delivery then one retry then success, exhaustion without requeue, unknown name, invalid JSON, and retry transport failure preserving the handler exception.
- [ ] **Step 2: Run the focused tests** with `composer test -- tusk-runtime/tests/Jobs/JobProcessorTest.php tusk-runtime/tests/RoadRunner/RoadRunnerJobTaskTest.php tusk-runtime/tests/RoadRunner/RoadRunnerJobsModuleTest.php`; confirm expected failures.
- [ ] **Step 3: Implement retry configuration and processor** with default `max_attempts=3`, `delay_seconds=1`, attempt 1 on first delivery, a reserved attempt header, no automatic retry for poison messages, and one terminal `fail` after exhaustion.
- [ ] **Step 4: Verify all ack/retry/fail paths and exception precedence** in tests; run `composer test -- tusk-runtime/tests/Jobs tusk-runtime/tests/RoadRunner`; expect PASS.
- [ ] **Step 5: Commit** as `feat: process RoadRunner job tasks`.

### Task 4: RR mode selection, worker wiring, skeleton, and Framework docs

**Files:**
- Modify: `tusk-runtime/src/RuntimeConfiguration.php`, `tusk-runtime/src/RuntimeAdapterFactory.php`, `tusk-runtime/src/RuntimeModuleFactory.php`, `tusk-runtime/src/Kernel.php`
- Modify: `tusk-core/src/Foundation/Application.php`, `tusk-core/src/Foundation/ApplicationBuilder.php`
- Modify: `tusk-cli/stubs/bootstrap-app.stub`, `tusk-cli/stubs/config-app.stub`, `tusk-cli/src/Generator/ProjectGenerator.php`
- Modify: `tusk-runtime/tests/RuntimeAdapterFactoryTest.php`, `tusk-runtime/tests/KernelTest.php`, `tusk-cli/tests/Generator/ProjectGeneratorTest.php`, `tusk-core/tests/Foundation/ApplicationBuilderTest.php`
- Modify: `tusk-runtime/README.md`, `tusk-cli/README.md`, root `README.md` (where job/runtime guidance belongs)

**Interfaces:**
- Consumes Tasks 1–3.
- `RuntimeModeFactory::create(string $mode, ...)` selects HTTP or Jobs mode from `RR_MODE`, defaulting to HTTP; Jobs mode starts the RoadRunner consumer loop and never wraps tasks in request hooks.
- Skeleton exposes `app/Jobs`, `#[AsJob]`, `JobHandlerInterface`, `withJobs(__DIR__.'/../app/Jobs')`, producer example using JSON, and retry config with the documented defaults.

- [ ] **Step 1: Write failing worker-mode tests** for absent/`http` mode, `jobs` mode, unsupported mode, no HTTP request lifecycle in Jobs mode, and unchanged existing HTTP adapter behavior.
- [ ] **Step 2: Run focused tests** with `composer test -- tusk-runtime/tests/RuntimeAdapterFactoryTest.php tusk-runtime/tests/KernelTest.php tusk-cli/tests/Generator/ProjectGeneratorTest.php`; confirm Jobs mode is not wired.
- [ ] **Step 3: Implement mode dispatch and consumer loop** using RoadRunner `Consumer::waitTask()`; preserve same worker entry point and ensure shutdown exits the loop through Engine/RoadRunner worker lifecycle.
- [ ] **Step 4: Extend generated skeleton and docs** with a compilable named-job example and migration notes for at-least-once delivery, idempotency, JSON payloads, retry bounds, and driver-specific failed-message behavior.
- [ ] **Step 5: Run Framework gates** (`composer test`, `composer analyse`, and the repository's formatter/lint command); record any pre-existing findings separately and confirm no new failures.
- [ ] **Step 6: Commit, open the Framework PR, and wait for green CI/review** before starting Engine implementation; retain all legacy queue files in this PR.

## Phase 2 — Engine Configuration and Integration PR

### Task 5: Typed Jobs config, validation, and RoadRunner projection

**Files:**
- Modify: `tusk-engine/internal/config/config.go`, `tusk-engine/internal/config/config_test.go`
- Modify: `tusk-engine/internal/roadrunner/config.go`, `tusk-engine/internal/roadrunner/config_test.go`
- Modify: `tusk-engine/internal/cli/cli.go` only if config projection currently requires adapting a copied config
- Modify: Engine config docs and sample `tusk.json`

**Interfaces:**
- Produces `Config.Jobs JobsConfig`, `JobsConfig{Consume []string, Pipelines map[string]JobPipelineConfig}`, and `JobPipelineConfig{Driver string, Config map[string]any}`.
- `JobsConfig.Validate() error` checks identifiers, duplicate/missing pipelines, required driver/config shape, and safe environment placeholders without resolving env vars.
- `roadrunner.Project(*config.Config)` emits deterministic `jobs.consume`/`jobs.pipelines`; omit Jobs keys when no pipelines exist.

- [ ] **Step 1: Write failing Go tests** for valid multi-pipeline projection, missing consumed pipeline, duplicate/invalid names, empty driver, unsupported config value, `${QUEUE_PASSWORD}` preservation, `${NAME:-DEFAULT}` preservation, malformed interpolation rejection, and errors that never contain configured secret values.
- [ ] **Step 2: Run focused tests** with `go test ./internal/config ./internal/roadrunner`; confirm failures on absent Jobs fields/projection.
- [ ] **Step 3: Implement typed config and validation** without expanding env references; include Jobs in config merge/loading and validation paths.
- [ ] **Step 4: Implement deterministic YAML projection** and assert HTTP-only output is unchanged when `jobs` is omitted.
- [ ] **Step 5: Run Engine checks** with `go test ./internal/config ./internal/roadrunner ./internal/cli`; expect PASS and no secret-bearing error output.
- [ ] **Step 6: Commit** as `feat: configure RoadRunner job pipelines`.

### Task 6: Engine-managed real RoadRunner Jobs smoke

**Files:**
- Modify: `tusk-engine/test/skeleton/smoke.ps1`
- Modify: Framework skeleton fixtures/tests if the generated job producer/handler needs a deterministic test endpoint
- Modify: `.github/workflows/*` only to ensure this existing smoke job runs under the supported Linux/PowerShell runner and uses the Framework commit pin policy
- Test: Engine's existing skeleton smoke workflow and `tusk-engine/internal/packaging/framework_contract_test.go` as needed

**Interfaces:**
- Consumes the merged Framework named-job API and Engine Jobs config from Tasks 4–5.
- Produces integration evidence for HTTP producer dispatch, RoadRunner pipeline consumption, handler side effect, a retry, HTTP availability, and clean Engine/RoadRunner/worker shutdown.

- [ ] **Step 1: Extend smoke fixture assertions** to generate an app with one `#[AsJob]` handler, an HTTP dispatch route, a side-effect marker, a one-time failure, and a configured RoadRunner memory pipeline.
- [ ] **Step 2: Run the smoke test in isolation** using the repository's documented Linux/PowerShell command; confirm it fails before Jobs integration is wired and leaves no orphan processes.
- [ ] **Step 3: Implement only the minimum smoke harness/config changes** needed to exercise the actual generated Engine runtime config; do not fake-consume or invoke the handler directly from the test harness.
- [ ] **Step 4: Assert end-to-end outcomes**: HTTP dispatch accepted, job marker written after retry, configured attempt count respected, HTTP endpoint remains healthy, Engine and RoadRunner exit after graceful signal, no worker remains.
- [ ] **Step 5: Run full Engine Go tests and smoke workflow**; capture Framework commit SHA and smoke output as PR evidence.
- [ ] **Step 6: Open the Engine PR against the merged Framework commit and wait for green CI/review**; do not begin legacy removal until this smoke is green on the PR commit.

## Phase 3 — Legacy Queue Cleanup PR (gated)

### Task 7: Remove legacy database queue consumer after smoke acceptance

**Precondition:** Framework consumer PR and Engine integration PR are merged; the real Engine-managed smoke passed on the exact merged Framework commit; user-visible migration note and release compatibility policy are present.

**Files:**
- Delete: `tusk-cli/src/Commands/QueueWorkerCommand.php`
- Delete: `tusk-events/src/Queue/QueueInterface.php`, `tusk-events/src/Queue/DatabaseQueue.php` and only the queue-specific schema/dependency code proven unused by repository search
- Modify: command registration/compiler fixtures and `tusk-cli/tests/CommandCompilerTest.php`
- Delete/modify: `tusk-events/tests/Queue/DatabaseQueueTest.php` and other tests that exercise only removed legacy APIs
- Modify: `tusk-events/composer.json`, `tusk-cli/composer.json`, lockfiles only if dependencies become unused
- Modify: root/package READMEs and migration guide

**Interfaces:**
- Preserve `Tusk\Contracts\Runtime\Capabilities\QueueInterface`, `QueueMessageInterface`, and `Tusk\Runtime\RoadRunner\RoadRunnerJobs`.
- Remove only the class-name/database queue path and `queue:work`; retain unrelated event-bus and database functionality.

- [ ] **Step 1: Search all supported packages and docs** for `QueueWorkerCommand`, `queue:work`, `Tusk\Events\Queue`, `DatabaseQueue`, and queue-only schema/dependencies; write the exact removal inventory into the PR description.
- [ ] **Step 2: Add/update architecture contract tests** proving producer capability remains and no legacy command/API is exposed after cleanup.
- [ ] **Step 3: Remove the legacy implementation and only verified-unused dependencies/schema**; preserve general database/event components.
- [ ] **Step 4: Update migration docs** with old class/array payload to named handler/JSON payload mapping, at-least-once/idempotency warning, retry behavior, and driver-specific failed-message handling.
- [ ] **Step 5: Run Framework full test, static analysis, formatter, package dependency checks, and the Engine-managed smoke again**; verify the old `while.alwaysTrue` PHPStan finding disappears because its command was removed, not suppressed.
- [ ] **Step 6: Open cleanup PR, wait for all checks/review, then merge only when green**; add an explicit breaking-change release note.

## Final Verification and Whole-Branch Review

- [ ] Run Framework tests, PHPStan, formatting, and Composer validation after all three PRs are merged.
- [ ] Run Engine Go tests, race detector where toolchain support is available, and the real RoadRunner skeleton smoke.
- [ ] Confirm HTTP-only applications produce unchanged behavior/configuration and Jobs config does not leak secret values in logs/errors.
- [ ] Confirm no `QueueWorkerCommand`, `queue:work`, or `Tusk\Events\Queue` references remain except migration documentation/history.
- [ ] Review all PRs for API consistency, test evidence, graceful shutdown, exception precedence, bounded retries, and release compatibility note.

