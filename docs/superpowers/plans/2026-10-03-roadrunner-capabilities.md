# RoadRunner Capabilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose RoadRunner Jobs, KV, Locks, Metrics, Logger, HTTP, and gRPC through lifecycle-aware Tusk contracts and modules without duplicating RoadRunner supervision or IPC.

**Architecture:** Provider-neutral contracts live in `tusk-contracts`; the Tusk Runtime owns a capability registry, module lifecycle, one Goridge RPC factory per worker, and RoadRunner adapters. The existing HTTP adapter remains backward-compatible while worker-mode boundaries make gRPC and future modes explicit.

**Tech Stack:** PHP ^8.2, Composer, PSR-3, PSR container contracts, PHPUnit 10, PHPStan 2, Laravel Pint, `spiral/goridge`, `spiral/roadrunner-http`, `spiral/roadrunner-grpc`, `spiral/roadrunner-jobs`, `spiral/roadrunner-kv`, `spiral/roadrunner-metrics`, `roadrunner-php/app-logger`, and `roadrunner-php/lock`.

**Spec:** `docs/superpowers/specs/2026-10-03-roadrunner-capabilities-design.md`

## Global Constraints

- PHP support remains `^8.2`; CI must pass on PHP 8.2, 8.3, and 8.4.
- RoadRunner owns worker pools, supervision, plugin lifecycle, and Goridge IPC.
- Application code depends on Tusk contracts; RoadRunner SDK types stay inside `tusk-runtime`.
- All RPC-backed capabilities share one lifecycle-safe RPC connection per worker and never create an RPC client per request.
- Capabilities are explicitly registered; undeclared capabilities resolve to a dedicated unavailable-capability error.
- Request scope remains exclusively controlled by `LifecycleManager`; adapters must not reset it themselves.
- No default unit or contract test may require a live RoadRunner daemon.
- RoadRunner is the only supported runtime path and owns the worker transport.
- RoadRunner configuration remains the source of truth for plugin drivers, endpoints, pools, and logger output.
- Temporal is an extension point only; it is not implemented in this plan.

## Review Focus

- **Capability declaration:** an undeclared capability must fail with its name and required plugin; cover in Task 2 registry tests.
- **RPC reuse:** repeated capability resolution in one worker must reuse one RPC instance and never construct it per request; cover in Task 3 factory/provider tests.
- **Queue boundary:** producer dispatch must not start a consumer pool, and consumer acknowledgement/retry must use a separate task contract; cover in Task 4 tests.
- **Lock cleanup:** `withLock` must release in `finally` and preserve the application exception if release also fails; cover in Task 4 lock tests.
- **Worker-mode isolation:** HTTP lifecycle behavior must remain unchanged while gRPC service registration uses its own module; cover in Task 5 adapter/module tests.

---

### Task 1: Align Composer dependencies and container registration

**Files:**
- Modify: `composer.json`
- Modify: `tusk-runtime/composer.json`
- Modify: `tusk-contracts/src/Container/ContainerInterface.php`
- Modify: `tusk-core/src/Container/Container.php`
- Modify: `tusk-core/src/Container/ContainerCompiler.php`
- Modify: `tusk-core/tests/Container/ContainerCompilerTest.php`
- Modify: `tusk-runtime/tests/KernelTest.php`
- Test: `tusk-core/tests/Container/ContainerRegistrationTest.php`

**Interfaces:**
- Produces `ContainerInterface::instance(string $id, object $instance): void`, matching the method already implemented by the concrete and compiled containers.
- Produces Composer requirements for the RoadRunner SDKs listed in the spec, constrained to versions compatible with the existing `spiral/roadrunner-http` major line.

- [ ] **Step 1: Write the failing container-registration test**

  Add `ContainerRegistrationTest::test_interface_can_bind_an_existing_capability_instance()` that type-hints `ContainerInterface`, calls `instance(KeyValueStoreInterface::class, $fake)`, and asserts `get(KeyValueStoreInterface::class)` returns the same object.

- [ ] **Step 2: Run the focused test to verify it fails**

  Run: `vendor/bin/phpunit tusk-core/tests/Container/ContainerRegistrationTest.php`

  Expected: FAIL because `ContainerInterface` does not expose `instance` yet.

- [ ] **Step 3: Add the registration contract and dependencies**

  Add `instance` to `ContainerInterface`; ensure the generated container emitted by `ContainerCompiler` continues to implement the method; add `spiral/goridge:^4.2`, `spiral/roadrunner-grpc:^3.3`, `spiral/roadrunner-jobs:^4.6.3`, `spiral/roadrunner-kv:^4.0`, `spiral/roadrunner-metrics:^3.0`, `roadrunner-php/app-logger:^1.0`, and `roadrunner-php/lock:^1.0` to both Composer manifests without changing the PHP floor or the existing `spiral/roadrunner-http:^4.1` constraint.

- [ ] **Step 4: Update test doubles and verify the focused suite**

  Add the no-op `instance` method to the test containers implementing `ContainerInterface`, then run:

  ```text
  vendor/bin/phpunit tusk-core/tests/Container/ContainerRegistrationTest.php tusk-core/tests/Container/ContainerCompilerTest.php tusk-runtime/tests/KernelTest.php
  ```

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```text
  git add composer.json tusk-runtime/composer.json tusk-contracts tusk-core tusk-runtime/tests
  git commit -m "feat: prepare runtime capability dependencies"
  ```

### Task 2: Add provider-neutral capability contracts and registry

**Files:**
- Create: `tusk-contracts/src/Runtime/Capabilities/CapabilityRegistryInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/CapabilityProviderInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/CapabilityUnavailableException.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/QueueInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/QueueMessageInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/JobTaskInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/KeyValueStoreInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/LockInterface.php`
- Create: `tusk-contracts/src/Runtime/Capabilities/MetricsInterface.php`
- Create: `tusk-contracts/src/Runtime/Modules/RuntimeModuleInterface.php`
- Create: `tusk-runtime/src/Capabilities/CapabilityRegistry.php`
- Create: `tusk-runtime/src/Modules/RuntimeModuleRegistry.php`
- Test: `tusk-runtime/tests/Capabilities/CapabilityRegistryTest.php`
- Test: `tusk-runtime/tests/Modules/RuntimeModuleRegistryTest.php`

**Interfaces:**
- `CapabilityRegistryInterface::has(string $name): bool`.
- `CapabilityRegistryInterface::get(string $name): object`.
- `CapabilityProviderInterface::supports(string $name): bool`.
- `CapabilityProviderInterface::provide(string $name): object`.
- `QueueInterface::dispatch(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface`.
- `QueueInterface::create(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface`.
- `JobTaskInterface::acknowledge(): void`.
- `JobTaskInterface::retry(?int $delaySeconds = null): void`.
- `KeyValueStoreInterface::get(string $key, mixed $default = null): mixed` and `set(string $key, mixed $value, ?int $ttlSeconds = null): void`, plus `has`, `delete`, and `clear`.
- `LockInterface::acquire(string $name, ?int $ttlSeconds = null): bool`, `release(string $name): void`, and `withLock(string $name, callable $handler, ?int $ttlSeconds = null): mixed`.
- `MetricsInterface::increment`, `decrement`, `set`, `observe`, `declare`, and `unregister`, each accepting a metric name and associative labels where applicable.
- `RuntimeModuleInterface::name(): string`, `register(ContainerInterface $container): void`, `start(): void`, and `stop(): void`.

- [ ] **Step 1: Write failing contract and registry tests**

  Test a fake provider that supplies one capability, verify `has` and identity-preserving `get`, verify an undeclared name throws `CapabilityUnavailableException` containing the capability and provider/plugin remediation, and verify module registry starts modules in declaration order and stops them in reverse order.

- [ ] **Step 2: Run focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Capabilities/CapabilityRegistryTest.php tusk-runtime/tests/Modules/RuntimeModuleRegistryTest.php`

  Expected: FAIL because the contracts and implementations do not exist.

- [ ] **Step 3: Implement the contracts, exception, registry, and module registry**

  Keep contracts free of RoadRunner imports. Make registry resolution lazy per capability and cache the returned object for the registry's worker lifetime. Make `RuntimeModuleRegistry::stop()` attempt every module and rethrow the first failure after all modules receive stop.

- [ ] **Step 4: Run focused and existing lifecycle tests**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Capabilities tusk-runtime/tests/Modules tusk-runtime/tests/LifecycleManagerTest.php tusk-runtime/tests/AdapterLifecycleParityTest.php`

  Expected: PASS.

- [ ] **Step 5: Commit**

  ```text
  git add tusk-contracts/src/Runtime/Capabilities tusk-contracts/src/Runtime/Modules tusk-runtime/src/Capabilities tusk-runtime/src/Modules tusk-runtime/tests
  git commit -m "feat: add provider-neutral runtime capabilities"
  ```

### Task 3: Build the shared RoadRunner RPC provider and stateless adapters

**Files:**
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerRpcFactoryInterface.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerRpcFactory.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerCapabilityProvider.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerKv.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerMetrics.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerLogger.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerRpcFactoryTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerCapabilityProviderTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerKvTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerMetricsTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerLoggerTest.php`

**Interfaces:**
- `RoadRunnerRpcFactoryInterface::create(): Spiral\Goridge\RPC\RPCInterface`.
- `RoadRunnerRpcFactory::create()` constructs the RPC client from the RoadRunner worker environment and returns the same instance for the worker lifetime.
- `RoadRunnerCapabilityProvider` implements `CapabilityProviderInterface` for `kv`, `metrics`, and `logger`, constructs each SDK once from the shared RPC, and returns only Tusk contracts/PSR-3 objects.
- `RoadRunnerKv` implements `KeyValueStoreInterface`.
- `RoadRunnerMetrics` implements `MetricsInterface`.
- `RoadRunnerLogger` implements `Psr\Log\LoggerInterface`.

- [ ] **Step 1: Write failing RPC reuse and adapter mapping tests**

  Use a fake `RoadRunnerRpcFactoryInterface` and SDK doubles to assert: `create()` is called once; two resolutions of the same capability return the same adapter; KV values and TTL map correctly; metrics labels reach the SDK unchanged; logger context is encoded deterministically; and an unknown capability is rejected.

- [ ] **Step 2: Run focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/RoadRunner`

  Expected: FAIL because the provider and adapters do not exist.

- [ ] **Step 3: Implement the shared RPC factory and adapters**

  Use `RPC::fromGlobals()`/RoadRunner environment data only inside `RoadRunnerRpcFactory`. Keep SDK construction in the provider, keep adapters thin, translate provider exceptions to capability-specific runtime exceptions, and do not create request-scoped clients.

- [ ] **Step 4: Run focused tests and static analysis**

  Run:

  ```text
  vendor/bin/phpunit tusk-runtime/tests/RoadRunner
  vendor/bin/phpstan analyse --no-progress
  ```

  Expected: PASS with no new PHPStan errors.

- [ ] **Step 5: Commit**

  ```text
  git add tusk-runtime/src/RoadRunner tusk-runtime/tests/RoadRunner
  git commit -m "feat: bridge RoadRunner RPC capabilities"
  ```

### Task 4: Add Jobs and Locks with separate producer/consumer semantics

**Files:**
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerJobs.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerJobMessage.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerJobTask.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerLock.php`
- Create: `tusk-runtime/src/RoadRunner/RoadRunnerJobsModule.php`
- Modify: `tusk-runtime/src/RoadRunner/RoadRunnerCapabilityProvider.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerJobsTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerJobTaskTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerLockTest.php`
- Test: `tusk-runtime/tests/RoadRunner/RoadRunnerJobsModuleTest.php`

**Interfaces:**
- `RoadRunnerJobs` implements `QueueInterface` and maps `dispatch`/`create` to `Spiral\RoadRunner\Jobs\Jobs` and its queue/task objects.
- `RoadRunnerJobMessage` implements `QueueMessageInterface` and preserves the RoadRunner task id, queue, name, payload, and headers.
- `RoadRunnerJobTask` implements `JobTaskInterface` and maps `acknowledge` and `retry` to the received RoadRunner task.
- `RoadRunnerLock` implements `LockInterface` and maps acquire/release to `RoadRunner\Lock\Lock`.
- `RoadRunnerJobsModule` implements `RuntimeModuleInterface`, registers the consumer handler boundary, and never owns or configures a RoadRunner worker pool.

- [ ] **Step 1: Write failing Jobs and Lock tests**

  Assert dispatch preserves queue/name/payload/headers, task acknowledgement/retry calls the correct SDK methods, producer construction does not call consumer APIs, locks release after successful and failing callbacks, and a callback exception wins over a release exception.

- [ ] **Step 2: Run focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/RoadRunner/RoadRunnerJobsTest.php tusk-runtime/tests/RoadRunner/RoadRunnerJobTaskTest.php tusk-runtime/tests/RoadRunner/RoadRunnerLockTest.php tusk-runtime/tests/RoadRunner/RoadRunnerJobsModuleTest.php`

  Expected: FAIL because the adapters and module do not exist.

- [ ] **Step 3: Implement the Jobs, task, lock, and module adapters**

  Keep dispatching and consuming separate. Make `withLock` use `try/finally`; when both the callback and release fail, rethrow the callback exception and log/attach the cleanup failure through the runtime's established error path. Make module startup validate only the required RoadRunner mode/configuration.

- [ ] **Step 4: Run focused tests and the full PHPUnit suite**

  Run:

  ```text
  vendor/bin/phpunit tusk-runtime/tests/RoadRunner
  vendor/bin/phpunit
  ```

  Expected: PASS; the full suite remains at least the 55 baseline tests before new tests are counted.

- [ ] **Step 5: Commit**

  ```text
  git add tusk-runtime/src/RoadRunner tusk-runtime/tests/RoadRunner
  git commit -m "feat: add RoadRunner jobs and locks"
  ```

### Task 5: Establish HTTP/gRPC worker modules and Kernel integration

**Files:**
- Create: `tusk-runtime/src/Modules/RoadRunnerHttpModule.php`
- Create: `tusk-runtime/src/Modules/RoadRunnerGrpcModule.php`
- Modify: `tusk-runtime/src/Adapters/RoadRunnerAdapter.php`
- Modify: `tusk-runtime/src/Kernel.php`
- Modify: `tusk-runtime/src/RuntimeAdapterFactory.php`
- Test: `tusk-runtime/tests/Modules/RoadRunnerHttpModuleTest.php`
- Test: `tusk-runtime/tests/Modules/RoadRunnerGrpcModuleTest.php`
- Modify: `tusk-runtime/tests/Adapters/RoadRunnerAdapterTest.php`
- Modify: `tusk-runtime/tests/KernelTest.php`

**Interfaces:**
- `RoadRunnerHttpModule` owns the current PSR-7 worker loop and continues to implement `RuntimeAdapterInterface` behavior used by `RoadRunnerAdapter`.
- `RoadRunnerGrpcModule` owns `Spiral\RoadRunner\GRPC\Server` registration and exposes a Tusk service registry without leaking the server object to application services.
- `RoadRunnerAdapter` remains the compatibility name returned for the default `roadrunner` runtime and delegates HTTP execution to `RoadRunnerHttpModule`.
- `Kernel` accepts an optional `RuntimeModuleRegistry`, starts modules after application/worker lifecycle start, and stops all modules before worker/application teardown completes; `RuntimeAdapterInterface` itself remains unchanged.

- [ ] **Step 1: Write failing module and Kernel tests**

  Assert the compatibility adapter still reports `roadrunner` and can stop before start, HTTP module preserves request-handler lifecycle behavior, gRPC module registers each service exactly once, and Kernel starts/stops modules in the documented order even when the worker adapter throws.

- [ ] **Step 2: Run focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/Modules tusk-runtime/tests/Adapters/RoadRunnerAdapterTest.php tusk-runtime/tests/KernelTest.php`

  Expected: FAIL because the module boundary and Kernel integration do not exist.

- [ ] **Step 3: Implement the worker modules and Kernel integration**

  Extract the existing HTTP loop without changing response/error behavior. Keep lifecycle state in `LifecycleManager`; modules only register/start/stop their worker resources. Add the gRPC service registry boundary and make the default factory return the compatibility HTTP adapter.

- [ ] **Step 4: Run adapter parity, Kernel, and full tests**

  Run:

  ```text
  vendor/bin/phpunit tusk-runtime/tests/Modules tusk-runtime/tests/Adapters tusk-runtime/tests/KernelTest.php tusk-runtime/tests/AdapterLifecycleParityTest.php
  vendor/bin/phpunit
  ```

  Expected: PASS with no regression in native compatibility behavior.

- [ ] **Step 5: Commit**

  ```text
  git add tusk-contracts/src/Runtime/RuntimeAdapterInterface.php tusk-runtime/src tusk-runtime/tests
  git commit -m "feat: add RoadRunner worker modules"
  ```

### Task 6: Add bootstrap configuration, documentation, and final verification

**Files:**
- Create: `tusk-runtime/src/RuntimeConfiguration.php`
- Create: `tusk-runtime/src/RuntimeModuleFactory.php`
- Modify: `tusk-cli/src/Commands/RunCommand.php`
- Modify: `tusk-runtime/src/RuntimeAdapterFactory.php`
- Modify: `tusk-runtime/README.md`
- Modify: `tusk-runtime/SPEC.md`
- Modify: `tusk-contracts/README.md`
- Modify: `README.md`
- Test: `tusk-runtime/tests/RuntimeConfigurationTest.php`
- Test: `tests/Integration/RuntimeBootstrapIntegrationTest.php`

**Interfaces:**
- `RuntimeConfiguration::fromArray(array $config): self` reads `runtime.adapter` and `runtime.modules`, defaulting to the existing RoadRunner HTTP path.
- `RuntimeConfiguration::modules(): list<string>` returns normalized module names and rejects unknown module names with an actionable exception.
- `RuntimeModuleFactory::fromConfiguration(RuntimeConfiguration $configuration): RuntimeModuleRegistry` creates the configured capability and worker modules without changing RoadRunner plugin configuration.
- `Kernel::__construct(ContainerInterface $container, RuntimeAdapterInterface $adapter, ?LifecycleManagerInterface $lifecycle = null, ?RuntimeModuleRegistry $modules = null)` preserves the existing three-argument call sites.
- `RunCommand` accepts an array returned by the application bootstrap file and passes its `runtime` section to the Kernel/module registry without changing existing bootstrap files that return `null`.

- [ ] **Step 1: Write failing configuration and bootstrap tests**

  Assert the documented array selects HTTP plus declared capabilities, default configuration remains RoadRunner HTTP, unknown module names identify the invalid value, and an existing bootstrap that returns `null` still starts with the legacy behavior.

- [ ] **Step 2: Run focused tests to verify they fail**

  Run: `vendor/bin/phpunit tusk-runtime/tests/RuntimeConfigurationTest.php tests/Integration/RuntimeBootstrapIntegrationTest.php`

  Expected: FAIL because configuration parsing and bootstrap handoff do not exist.

- [ ] **Step 3: Implement configuration handoff and documentation**

  Parse only Tusk module selection in PHP; leave RoadRunner plugin drivers, endpoints, pools, and logging output in `.rr.yaml`. Document the container contracts, required plugin names, `RR_RPC`, worker modes, and the future Temporal extension point.

- [ ] **Step 4: Run the complete verification matrix**

  Run:

  ```text
  vendor/bin/phpunit
  vendor/bin/phpstan analyse --no-progress
  git diff --name-only origin/main...HEAD -- '*.php'
  Run `vendor/bin/pint --test` with the PHP paths printed by the previous command.
  composer validate --strict
  ```

  Expected: PHPUnit, PHPStan, Pint on changed files, and Composer validation pass. The known repository-wide Pint baseline failure must remain limited to pre-existing files outside this branch's changed set.

- [ ] **Step 5: Commit**

  ```text
  git add tusk-cli/src/Commands/RunCommand.php tusk-runtime/src tusk-runtime/tests tusk-runtime/README.md tusk-runtime/SPEC.md tusk-contracts/README.md README.md
  git commit -m "docs: document RoadRunner capabilities"
  ```

### Task 7: Review, issue update, and pull request handoff

**Files:**
- Modify: `docs/superpowers/plans/2026-10-03-roadrunner-capabilities.md` only if implementation decisions require plan corrections.

- [ ] **Step 1: Run the complete verification commands again from a clean worktree**

  Run PHPUnit, PHPStan, Pint on changed PHP files, Composer validation, and the repository's configured CI-equivalent commands. Record exact totals and any environment-specific limitation.

- [ ] **Step 2: Review the diff against the design spec**

  Confirm no RoadRunner SDK type leaked into provider-neutral contracts, no worker pool or IPC was duplicated, all lifecycle failure paths are covered, and documentation matches the actual configuration names.

- [ ] **Step 3: Commit any review corrections**

  Use focused commits with messages that describe the correction; do not mix generated dependency artifacts or unrelated formatting changes.

- [ ] **Step 4: Update issue #17 and open the PR**

  The PR body must use the repository template, link issue #17, list the capability matrix, describe the no-live-RoadRunner test strategy, document the missing/required PHP extensions if relevant, and identify the reviewer focus areas.
