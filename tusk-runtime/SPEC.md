# Tusk Runtime Technical Spec (v0.2)

## Overview
The **Tusk Runtime** is responsible for turning a PHP application into a persistent process. It manages the lifecycle of workers, handles signals, and integrates with the event loop.

## Core Components

### 1. The Runner (`tusk run`)
The main entry point that:
- Initializes the `Tusk Container`.
- Boots the configured `Runtime Adapter` (e.g., RoadRunner, Swoole, or native PHP loop).
- Sets up signal handlers (`SIGTERM`, `SIGINT`).

### 2. Lifecycle Control Plane

`Tusk\Runtime\LifecycleManager` owns application, worker, and request transitions. The Kernel starts the application and worker, wraps the transport handler, and always performs worker/application teardown when the adapter exits. Request cleanup runs exactly once in a `finally` path and preserves the handler's original exception when cleanup also fails.

Runtime adapters own only transport and blocking concerns. RoadRunner is the primary transport; the native NDJSON loop remains a compatibility adapter. Neither adapter creates or resets container scopes.

### 3. Supervisor & Worker Management
Inspired by Erlang/Spring, Tusk manages a pool of workers:
- **Master Process**: Remains lean, monitors child workers.
- **Worker Processes**: Execute the application logic (Domain).
- **Auto-Restart**: If a worker crashes, the Supervisor replaces it immediately.

### 4. Event Loop Integration
Tusk aims to be runtime-agnostic but optimized for modern engines:
- **Phase 1**: Support for **RoadRunner** (via RPC) and **Swoole**.
- **Phase 2**: Native PHP fibers-based loop for isolated tasks.

## Application Lifecycle in Runtime

```mermaid
sequenceDiagram
    participant CLI as Tusk CLI
    participant Master as Supervisor (Master)
    participant Worker as Worker Process
    participant App as Application Domain

    CLI->>Master: start app.php
    Master->>Worker: spawn(N)
    Worker->>App: #[OnStart]
    loop Persistent Execution
        App->>App: Handle Tasks/Requests
    end
    Master->>Worker: SIGTERM/Reload
    Worker->>App: #[OnShutdown]
    Worker->>Master: exited
```

The transport-neutral lifecycle sequence is:

```text
application.start -> worker.start -> (request.start -> handler -> request.end)*
worker.stop -> application.stop
```

Each transition is idempotent where meaningful, while impossible transitions fail explicitly. Teardown hooks continue in declaration order after a hook failure and report the first failure after all hooks have had an opportunity to run.

## Configuration

```php
#[Runtime(
    workers: 4,
    max_requests: 1000,
    dispatch_mode: 'round-robin'
)]
class AppRuntime {}
```

## Error Handling & Reliability
- **Isolate Crashes**: A fatal error in one worker does not kill the entire application.
- **Graceful Shutdown**: Ensures inflight requests/tasks are finished (or timed out) before exiting.

---
*Status: Draft v0.2*
