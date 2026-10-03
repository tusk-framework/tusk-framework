# RoadRunner Capabilities Design

## Status

Approved in conversation as the next architectural slice after the persistent lifecycle foundation merged in PR #18.

## Goal

Make RoadRunner capabilities first-class Tusk services without coupling application code to RoadRunner SDK classes or reimplementing RoadRunner's worker pools, supervision, or Goridge IPC.

The result is a Tusk application model in which queues, key-value storage, distributed locks, metrics, and runtime logging are consumed through stable Tusk contracts, while HTTP and gRPC remain explicit RoadRunner worker modes managed by the Tusk Runtime.

## Context

RoadRunner already provides the process supervision, worker pools, plugin lifecycle, and RPC boundary. Its PHP ecosystem exposes Jobs, KV, Metrics, application logging, Locks, HTTP, and gRPC clients. The Tusk Runtime currently owns the persistent lifecycle and has an HTTP adapter, but it does not yet present those capabilities through a coherent framework API.

Tusk must add the framework boundary around those capabilities, not another runtime underneath them. The `tusk-engine` remains the Go control plane that starts, monitors, configures, and stops RoadRunner. The PHP Framework remains responsible for application contracts, dependency injection, lifecycle hooks, and request/worker behavior.

## Design principles

1. **RoadRunner owns the runtime mechanics.** Tusk does not implement a second worker pool, scheduler, supervisor, or IPC protocol.
2. **Application code depends on Tusk contracts.** RoadRunner SDK types stay inside `tusk-runtime` adapters and boot modules.
3. **One RPC boundary per worker.** All RPC-backed capabilities use a shared, lifecycle-safe Goridge RPC factory.
4. **Capabilities are explicit.** A capability is registered only when the application declares or requests it; unused plugins do not add application services.
5. **Lifecycle is deterministic.** Capability clients are created at application/worker scope, request state is never leaked, and locks are released during teardown.
6. **Failures are visible.** Misconfigured or unavailable RoadRunner capabilities fail during boot or first use with actionable exceptions; silent fallbacks are not allowed.
7. **The provider boundary is testable.** Every capability has a provider-neutral fake and contract tests that do not require a running RoadRunner process.

## Architecture

```text
Tusk application code
        |
        v
Tusk contracts and PSR interfaces
        |
        v
Capability registry + lifecycle-aware module boot
        |
        v
RoadRunner adapters (tusk-runtime)
        |
        +--> RoadRunner PHP SDKs
        |
        +--> one Goridge RPC connection
        |
        v
RoadRunner plugins and worker pools
```

The runtime is divided into two kinds of integration:

- **Application capabilities:** queues, KV, locks, metrics, and logging. These are injectable services backed by RoadRunner RPC.
- **Worker modes:** HTTP and gRPC. These are runtime modules that bind a Tusk handler/service registry to a RoadRunner worker protocol. They do not expose the RoadRunner worker loop to application code.

The existing `RoadRunnerAdapter` becomes the HTTP module implementation behind the worker-mode boundary. The new module boundary must preserve the current `RuntimeAdapterInterface` compatibility while allowing future modes to be added without changing `Kernel` lifecycle semantics.

## Public contracts

Provider-neutral contracts live under `tusk-contracts/src/Runtime/Capabilities/`.

### Capability registry

`CapabilityRegistryInterface` provides named capability resolution from the container and exposes whether a capability is registered. Resolution is typed at the call site and throws a dedicated `CapabilityUnavailableException` when the capability was not configured or its provider cannot initialize.

`CapabilityProviderInterface` is implemented by runtime integrations. It declares the capability names it supports and creates the corresponding service from the current runtime context. Providers must not be exposed to application code.

### Queue

`QueueInterface` exposes the minimum Tusk producer API:

- `dispatch(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface`;
- `create(string $queue, string $name, string $payload, array $headers = []): QueueMessageInterface`;

The RoadRunner adapter maps these operations to `spiral/roadrunner-jobs`. Consuming jobs is a worker mode and is represented by a dedicated runtime module with a separate `JobTaskInterface` exposing `acknowledge(): void` and `retry(?int $delaySeconds = null): void`; producer operations must not start or manage a consumer pool. This keeps enqueueing independent from worker-side delivery semantics.

### Key-value store

`KeyValueStoreInterface` exposes `get`, `set`, `has`, `delete`, and `clear`, with `set` accepting a nullable TTL in seconds. Serialization is owned by the adapter boundary and is deterministic for scalar, array, and serialized payloads.

### Distributed lock

`LockInterface` exposes `acquire`, `release`, and `withLock`. `withLock` must release the lock in a `finally` block and preserve the handler exception if both the handler and release fail. Lock ownership is process-safe within the RoadRunner instance; the documentation must state the RoadRunner plugin's instance scope.

### Metrics

`MetricsInterface` exposes `increment`, `decrement`, `set`, `observe`, `declare`, and `unregister`. Labels are passed as an associative array and validated before crossing the RPC boundary. Metric names and labels are not silently normalized by Tusk.

### Logging

Tusk uses `Psr\Log\LoggerInterface` as the public logging contract. The RoadRunner application logger adapter implements PSR-3 and maps structured context to a deterministic message representation before calling the RoadRunner app-logger SDK. The existing JSON logger remains available as a local logger; the runtime provider decides which implementation is bound for the selected runtime.

## RoadRunner runtime modules

RoadRunner integration lives under `tusk-runtime/src/RoadRunner/` and is split by responsibility:

- `RoadRunnerRpcFactory`: creates the worker RPC connection from `RR_RPC`/RoadRunner environment data and owns connection construction.
- `RoadRunnerCapabilityProvider`: registers and constructs the capability adapters from one RPC factory.
- `RoadRunnerJobs`: adapts the Jobs SDK to `QueueInterface`.
- `RoadRunnerKv`: adapts the KV SDK to `KeyValueStoreInterface`.
- `RoadRunnerLock`: adapts the lock SDK to `LockInterface`.
- `RoadRunnerMetrics`: adapts the Metrics SDK to `MetricsInterface`.
- `RoadRunnerLogger`: adapts the application logger SDK to `Psr\Log\LoggerInterface`.
- `RoadRunnerHttpModule`: owns the current PSR-7 HTTP worker binding and delegates request boundaries to `LifecycleManager`.
- `RoadRunnerGrpcModule`: defines the gRPC worker binding and service registration boundary without leaking the RoadRunner server object to application services.

The module/provider objects are created by the runtime bootstrap, not by individual request handlers. A module may register services during application start and release its resources during application stop, but it must not own the global lifecycle state.

## Lifecycle and error behavior

1. Application boot constructs the container, capability registry, RPC factory, and declared modules.
2. `LifecycleManager::applicationStart()` runs before capability provider initialization that needs application configuration.
3. `LifecycleManager::workerStart()` initializes worker-scoped adapters and validates required runtime environment such as `RR_RPC`.
4. Request start/end continues to be controlled exclusively by `LifecycleManager`; capability adapters do not reset the request scope themselves.
5. `LockInterface::withLock()` releases locks in `finally` and retains the original application exception when cleanup fails.
6. Worker stop closes or invalidates worker-scoped RPC-backed clients before container worker scope reset.
7. Application stop shuts down modules without attempting to stop RoadRunner itself; the Engine/RoadRunner process owns server shutdown.

Capability initialization failures include the capability name, required RoadRunner plugin, and remediation. Runtime stop failures follow the lifecycle foundation's existing exception precedence and teardown continuation rules.

## Configuration

RoadRunner configuration remains the source of truth for plugin configuration. Tusk adds only a framework-level declaration that selects the modules and capability bindings, for example:

```php
return [
    'runtime' => [
        'adapter' => 'roadrunner',
        'modules' => [
            'http',
            'capabilities.jobs',
            'capabilities.kv',
            'capabilities.lock',
            'capabilities.metrics',
            'capabilities.logger',
        ],
    ],
];
```

The framework validates that selected modules have the required RoadRunner plugin and `RR_RPC` environment. It does not duplicate queue drivers, KV drivers, metrics listeners, logger output, or pool settings from `.rr.yaml`.

## Dependency policy

The framework's RoadRunner runtime package will directly declare the compatible PHP SDKs required by the supported first-party modules:

- `spiral/roadrunner-http`;
- `spiral/roadrunner-grpc`;
- `spiral/roadrunner-jobs`;
- `spiral/roadrunner-kv`;
- `spiral/roadrunner-metrics`;
- `roadrunner-php/app-logger`;
- `roadrunner-php/lock`;
- `spiral/goridge` for the shared RPC boundary.

Constraints must stay on the current compatible RoadRunner major line and be verified together in CI. The application API must never require consumers to import these provider SDKs directly.

## Testing strategy

- Contract tests for every provider-neutral capability using in-memory fakes.
- Adapter tests using mocked SDK clients/RPC calls for serialization, method mapping, and error translation.
- Lifecycle tests proving application/worker initialization ordering and worker teardown behavior.
- Lock tests proving release in `finally` and exception precedence.
- Capability registry tests proving undeclared capabilities are unavailable and declared capabilities are singleton within the intended scope.
- HTTP adapter parity tests proving the existing request lifecycle remains unchanged.
- gRPC module tests proving service registration is isolated from the HTTP worker mode.
- Documentation examples validated for configuration names and container bindings.

No test may require a live RoadRunner daemon in the default test suite. A separate integration workflow may run a real RoadRunner binary once the repository has a deterministic binary setup.

## Documentation and rollout

The implementation updates:

- `tusk-runtime/README.md` with capability registration and `.rr.yaml` examples;
- `tusk-runtime/SPEC.md` with worker modes and lifecycle ownership;
- `tusk-contracts/README.md` with provider-neutral capability contracts;
- the root README with the supported RoadRunner capability matrix;
- issue #17 with links to the implementation PR and verification results.

Temporal is intentionally an extension point in this slice. Its worker mode and SDK integration will be a separate issue after the capability/module boundary has proven stable.

## Non-goals

- Reimplementing RoadRunner supervision, worker pools, or Goridge.
- Moving RoadRunner configuration into Tusk configuration.
- Building a gateway, service mesh, service discovery, or control-plane proxy in PHP.
- Adding Temporal support in the first implementation slice.
- Making the native adapter a second implementation of RoadRunner capabilities.
- Requiring a live RoadRunner process for unit or contract tests.

## Acceptance criteria

1. Each supported capability has a provider-neutral Tusk contract and a RoadRunner adapter.
2. The runtime creates one shared RPC boundary per worker and does not create per-request RPC clients.
3. Capabilities are registered through lifecycle-aware modules and are resolvable through the Tusk container.
4. HTTP behavior and lifecycle parity remain green.
5. Queue, KV, metrics, logger, and lock adapters are covered by deterministic tests with no live RoadRunner dependency.
6. gRPC has an explicit worker/module boundary and tests for service registration.
7. Documentation explains the split between Tusk configuration and `.rr.yaml`.
8. CI passes on PHP 8.2, 8.3, and 8.4 with PHPStan and Pint.
