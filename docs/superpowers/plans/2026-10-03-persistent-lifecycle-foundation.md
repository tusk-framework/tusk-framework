# Persistent Lifecycle Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Centralize application, worker, and request lifecycle behavior so the RoadRunner worker executes the persistent PHP lifecycle with exactly-once request cleanup.

**Architecture:** Add a transport-neutral lifecycle contract and manager around the existing container. The Kernel owns application/worker transitions and wraps the request handler; adapters remain responsible only for transport and no longer reset container scopes. The container compiler emits lifecycle hook metadata/calls so the request path does not use reflection when a compiled container is present.

**Tech Stack:** PHP 8.2+, PHPUnit 10, existing Tusk contracts/core/runtime packages, PHP attributes, generated PHP container code.

**Spec:** `docs/superpowers/specs/2026-10-03-persistent-lifecycle-foundation-design.md`

## Global Constraints

- `RuntimeAdapterInterface` continues to own transport and blocking behavior.
- Existing `OnStart` and `OnShutdown` applications remain compatible.
- Request-scoped services reset exactly once after every dispatch, including failures.
- Runtime adapters must not independently reset application container scopes.
- RoadRunner is the supported runtime and owns the worker transport.
- No new ORM, worker pool, gateway, service mesh, or Spiral Framework dependency is introduced.
- Lifecycle behavior must be testable without starting an external worker process.

## Review Focus

- A request handler throws: request-end hooks and scope cleanup still run while the original exception reaches the adapter.
- A hook throws during teardown: later teardown hooks still run and the failure is reported with its event/service context.
- `start()`/`stop()` are called repeatedly or the adapter exits unexpectedly: transitions and cleanup remain idempotent.
- A compiled container has aliases and scoped services: lifecycle hooks execute once per service instance without reflection on the hot path.
- A legacy service uses only `OnStart`/`OnShutdown`: it keeps the existing application-level behavior.

---

### Task 1: Define lifecycle contracts and explicit hook attributes

**Files:**
- Create: `tusk-contracts/src/Runtime/LifecycleEvent.php`
- Create: `tusk-contracts/src/Runtime/LifecycleManagerInterface.php`
- Create: `tusk-contracts/src/Attributes/OnWorkerStart.php`
- Create: `tusk-contracts/src/Attributes/OnWorkerStop.php`
- Create: `tusk-contracts/src/Attributes/OnRequestStart.php`
- Create: `tusk-contracts/src/Attributes/OnRequestEnd.php`
- Modify: `tusk-contracts/src/Container/ContainerInterface.php`
- Test: `tusk-runtime/tests/LifecycleContractTest.php`

**Interfaces:**
- `LifecycleEvent` is a string-backed enum with `application.start`, `worker.start`, `request.start`, `request.end`, `worker.stop`, and `application.stop` values.
- `LifecycleManagerInterface` exposes `applicationStart(): void`, `workerStart(): void`, `requestStart(): void`, `requestEnd(): void`, `workerStop(): void`, `applicationStop(): void`, and `wrap(callable $handler): callable`.
- `ContainerInterface` gains `runLifecycleHooks(string $event): void`; existing `runHooks(string $attributeClass): void` remains for compatibility during migration.
- Each new attribute targets methods only and carries no hidden behavior.

- [ ] **Step 1: Write failing contract tests**

  Add tests asserting the enum values, attribute targets, and the lifecycle manager/container method signatures through reflection.

- [ ] **Step 2: Run the focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/LifecycleContractTest.php`

  Expected: FAIL because the enum, interface, attributes, and container method do not exist.

- [ ] **Step 3: Implement the contracts and attributes**

  Keep the contracts dependency-free and use the existing `Tusk\Contracts` namespaces. Do not remove or rename `OnStart`, `OnShutdown`, `runHooks`, or `resetScope` in this task.

- [ ] **Step 4: Run the focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-runtime/tests/LifecycleContractTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-contracts/src tusk-runtime/tests/LifecycleContractTest.php
  git commit -m "feat: define persistent lifecycle contracts"
  ```

### Task 2: Add lifecycle hook metadata to the container and compiler

**Files:**
- Modify: `tusk-core/src/Container/ServiceScanner.php`
- Modify: `tusk-core/src/Container/Container.php`
- Modify: `tusk-core/src/Container/ContainerCompiler.php`
- Modify: `tusk-core/tests/Container/ContainerCompilerTest.php`
- Create: `tusk-core/tests/Container/LifecycleHooksTest.php`
- Modify: `tusk-web/tests/HttpKernelTest.php`
- Modify: `tusk-web/tests/Http/ArgumentBinderTest.php`

**Interfaces:**
- `ServiceScanner::scan()` adds a `hooks` map to each service definition: `array<string, list<string>>`, keyed by lifecycle event value and containing method names in declaration order.
- `Container::runLifecycleHooks(string $event): void` resolves services with hooks before invoking them, so lazy services still receive their lifecycle events; scope reset remains owned by the manager through `resetScope()`.
- The generated container implements `runLifecycleHooks(string $event): void` using precomputed direct method calls and does not instantiate `ReflectionClass` on that path.
- The generated container has distinct singleton, worker, and request instance stores; `worker` is not treated as prototype.

- [ ] **Step 1: Write failing scanner/compiler tests**

  Add a fixture service with worker and request attributes. Assert that scanner metadata contains the expected event-to-method map and that compiler output contains a lifecycle dispatch table/direct method call without `ReflectionClass` in `runLifecycleHooks()`.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-core/tests/Container/ContainerCompilerTest.php tusk-core/tests/Container/LifecycleHooksTest.php`

  Expected: FAIL because hook metadata and `runLifecycleHooks()` are not implemented.

- [ ] **Step 3: Implement runtime and compiled hook dispatch**

  Extend the scanner to inspect the supported lifecycle attributes and map legacy `OnStart`/`OnShutdown` to application events. Preserve deterministic service and method ordering. The interpreted container may use cached metadata and method invocation; the generated container must emit explicit calls from the metadata. Implement `request` and `worker` reset behavior in both container implementations.

- [ ] **Step 4: Run focused and existing container tests**

  Run: `vendor/bin/phpunit tusk-core/tests/Container`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-core/src tusk-core/tests
  git commit -m "feat: compile lifecycle hook metadata"
  ```

### Task 3: Implement the lifecycle manager state machine

**Files:**
- Create: `tusk-runtime/src/LifecycleManager.php`
- Create: `tusk-runtime/tests/LifecycleManagerTest.php`
- Modify: `tusk-runtime/composer.json`

**Interfaces:**
- `LifecycleManager` implements `LifecycleManagerInterface` and accepts `ContainerInterface` plus an optional `Psr\Log\LoggerInterface` for hook failure reporting.
- `wrap(callable $handler): callable` returns a callable that runs `requestStart()`, invokes the handler, always runs `requestEnd()`, and rethrows the original handler exception.
- Transition methods are idempotent for repeated application/worker start/stop calls and reject impossible transitions with `LogicException`.

- [ ] **Step 1: Write failing state and error-path tests**

  Cover normal event ordering, repeated start/stop calls, request cleanup after success, request cleanup after handler failure, reverse teardown order, and continuation after a teardown hook fails.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/LifecycleManagerTest.php`

  Expected: FAIL because `LifecycleManager` does not exist.

- [ ] **Step 3: Implement the state machine and wrapper**

  Track application and worker state explicitly. Invoke the container lifecycle events, reset the `request` scope exactly once in `requestEnd()`, reset the `worker` scope during worker teardown, and aggregate/log teardown failures without replacing an active handler exception. Add `psr/log:^3.0` as a direct runtime package dependency because the manager type-hints the PSR logger.

- [ ] **Step 4: Run focused tests to verify they pass**

  Run: `vendor/bin/phpunit tusk-runtime/tests/LifecycleManagerTest.php`

  Expected: PASS with zero failures.

- [ ] **Step 5: Commit**

  ```bash
  git add tusk-runtime/src/LifecycleManager.php tusk-runtime/tests/LifecycleManagerTest.php
  git commit -m "feat: add persistent lifecycle manager"
  ```

### Task 4: Integrate Kernel and remove lifecycle ownership from adapters

**Files:**
- Modify: `tusk-runtime/src/Kernel.php`
- Modify: `tusk-runtime/src/Adapters/RoadRunnerAdapter.php`
- Keep the runtime adapter boundary limited to RoadRunner.
- Create: `tusk-runtime/tests/KernelTest.php`
- Modify: `tusk-runtime/tests/Adapters/RoadRunnerAdapterTest.php`
- Create: `tusk-runtime/tests/AdapterLifecycleParityTest.php`
- Modify: `tusk-runtime/README.md`

**Interfaces:**
- `Kernel::__construct()` accepts an optional `LifecycleManagerInterface` after the existing container and adapter arguments, preserving current callers.
- `Kernel::start()` performs application start, worker start, adapter execution with the lifecycle-wrapped handler, worker stop, and application stop in `finally` blocks.
- Adapters retain `start(ContainerInterface, callable): void`, `stop(): void`, and `getName(): string`, but no longer call `resetScope()`.

- [ ] **Step 1: Write failing Kernel and parity tests**

  Use a fake adapter and fake container/lifecycle recorder. Assert that the RoadRunner path receives the wrapped request lifecycle and that no adapter calls `resetScope()` directly.

- [ ] **Step 2: Run focused tests to verify failure**

  Run: `vendor/bin/phpunit tusk-runtime/tests/KernelTest.php tusk-runtime/tests/AdapterLifecycleParityTest.php`

  Expected: FAIL because Kernel does not coordinate the lifecycle manager and adapters still reset scopes themselves.

- [ ] **Step 3: Integrate the manager and simplify adapters**

  Make Kernel own lifecycle transitions. Keep RoadRunner response/error handling in its adapter and move application scope reset to the shared manager.

- [ ] **Step 4: Run focused runtime tests**

  Run: `vendor/bin/phpunit tusk-runtime/tests`

  Expected: PASS with zero failures.

- [ ] **Step 5: Update runtime documentation and commit**

  Document the lifecycle sequence, scope rules, and the fact that RoadRunner is the supported deployment path.

  ```bash
  git add tusk-runtime/src tusk-runtime/tests tusk-runtime/README.md
  git commit -m "feat: unify lifecycle across runtime adapters"
  ```

### Task 5: Verify full framework compatibility and close the issue

**Files:**
- Modify: `tusk-core/SPEC.md`
- Modify: `tusk-runtime/SPEC.md`
- Modify: `tusk-runtime/README.md` if final wording needs alignment
- Test: existing repository test suites

- [ ] **Step 1: Run the complete test suite**

  Run: `vendor/bin/phpunit`

  Expected: PASS with zero failures across integration, core, web, runtime, security, events, and data suites.

- [ ] **Step 2: Run static analysis and formatting checks**

  Run: `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/pint --test`

  Expected: zero PHPStan errors and no formatting changes required. If a configured command is unavailable, record the exact limitation instead of replacing it with an unrequested tool.

- [ ] **Step 3: Review the diff against the spec**

  Confirm every acceptance criterion, Review Focus case, and global constraint is covered. Record any deliberate deviation in the Superpowers ledger before changing scope.

- [ ] **Step 4: Update specification status and issue #14**

  Mark the design/spec implementation status accurately, add the verification commands to the issue, and link the resulting PR or commit.

- [ ] **Step 5: Commit documentation/status updates**

  ```bash
  git add tusk-core/SPEC.md tusk-runtime/SPEC.md tusk-runtime/README.md
  git commit -m "docs: record persistent lifecycle foundation"
  ```
