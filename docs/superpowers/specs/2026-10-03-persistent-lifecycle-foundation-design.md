# Tusk Persistent Lifecycle Foundation

Status: Proposed design

## Intent

Make the persistent PHP lifecycle explicit and runtime-independent. The
framework must give application services the same reliable boundaries whether
the Engine runs RoadRunner or the native adapter, while keeping transport
concerns outside the container and domain code.

The first delivery is intentionally limited to the lifecycle foundation. It
does not introduce a new ORM, a proxy/gateway mode, or another worker pool.

## Current problem

Tusk already has `OnStart`, `OnShutdown`, service scopes, and request-scope
resetting, but their responsibilities are split across the kernel, container,
and runtime adapters:

- application start and shutdown hooks are invoked directly by `Kernel`;
- request cleanup is performed independently by RoadRunner and native loops;
- the same application can observe different lifecycle behavior per adapter;
- there are no explicit worker, request, or job lifecycle boundaries;
- adding reset hooks or resource finalization requires changing every adapter.

This is unsafe for long-lived PHP because a request can leak state into the
next request even when the transport itself is healthy.

## Design

### Lifecycle events

Add a small, transport-neutral lifecycle contract with these ordered events:

1. `application.start` — once before the runtime loop starts;
2. `worker.start` — once for the PHP worker process;
3. `request.start` — before dispatching one HTTP request or equivalent task;
4. `request.end` — in a `finally` path after dispatch, including failures;
5. `worker.stop` — once before a worker exits;
6. `application.stop` — once after the runtime loop has stopped.

The contract must be usable by queue and RPC adapters later without naming an
HTTP-specific type. A future `job.start`/`job.end` pair may reuse the same
mechanism, but is not part of this delivery.

### Lifecycle manager

Introduce a lifecycle manager in the framework/runtime boundary. It owns event
ordering and invokes registered lifecycle hooks. The manager must:

- be idempotent for application and worker start/stop;
- execute end/stop hooks in reverse registration order where teardown order
  matters;
- execute cleanup in `finally` blocks even when application code throws;
- reset request-scoped services exactly once per dispatch;
- preserve the original application exception while reporting hook failures;
- expose a testable event API without requiring a running RoadRunner process.

The manager may delegate hook discovery to the compiled container. Reflection
must not be required on the hot path once the compiled container is available.

### Service scopes

Keep the existing scopes and make their semantics explicit:

- `singleton`: application-level instance shared by all work in a worker;
- `worker`: instance owned by one PHP worker and reset when that worker ends;
- `request`: instance reset after every dispatch;
- `prototype`: a new instance for every resolution.

The first implementation may continue to use the existing container storage,
but all resets must be initiated by the lifecycle manager. Runtime adapters
must not independently reset container scopes.

### Hook compatibility

Existing `OnStart` and `OnShutdown` remain supported as aliases for
application-level lifecycle hooks. New hooks should be explicit about their
scope, using dedicated attributes or a single lifecycle attribute with an event
argument. The selected representation must be discoverable by the compiler and
must reject invalid event/scope combinations at build time.

For example, application code should be able to express worker cleanup without
depending on RoadRunner:

```php
#[Service(scope: 'worker')]
final class ResourcePool
{
    #[OnWorkerStart]
    public function open(): void {}

    #[OnWorkerStop]
    public function close(): void {}
}
```

The exact attribute names are an implementation decision constrained by this
behavior: they must be explicit, statically discoverable, and compatible with
the existing attributes.

### Runtime adapter boundary

`RuntimeAdapterInterface` continues to own transport and blocking behavior.
The kernel wraps the request handler with lifecycle entry/exit behavior before
passing it to the adapter. Adapters remain responsible for transport-specific
resource cleanup, such as releasing a RoadRunner request or closing an NDJSON
frame, but they do not own application container lifecycle.

The resulting flow is:

```text
Kernel
  application.start
  worker.start
  adapter.start(lifecycle-wrapped handler)
    request.start
    handler(request)
    request.end + request-scope reset
  worker.stop
  application.stop
```

This keeps the RoadRunner adapter thin and lets the native adapter exercise the
same PHP lifecycle contract.

## Error handling

- A request handler failure is returned to the adapter using the existing
  adapter-specific error behavior.
- A request-end hook must still run after a handler failure.
- A lifecycle hook failure must be logged with its event and service name.
- During shutdown, all applicable teardown hooks run; the first failure is
  returned after the remaining hooks have had a chance to execute.
- A lifecycle transition that is illegal for the current state fails loudly
  in development and is represented as a structured runtime error in the
  Engine-facing path.

## Testing strategy

Tests must prove behavior without RoadRunner or Swoole processes:

- event ordering for a normal application/worker/request lifecycle;
- idempotent start and stop transitions;
- request cleanup after successful and failed handlers;
- worker cleanup on adapter termination;
- reverse teardown ordering;
- hook failure reporting without skipping later cleanup;
- parity between the native and RoadRunner adapter test doubles;
- no duplicate request reset when an adapter exits unexpectedly;
- compiled hook metadata produces the same order as runtime discovery.

## Delivery decomposition

This design is the first of four independent deliveries:

1. **Persistent lifecycle foundation** — this document;
2. **Cycle integration** — `tusk-data-cycle` implementing Tusk data contracts,
   with a disposable Unit of Work per request/job and explicit heap cleanup;
3. **RoadRunner capability modules** — boot modules for HTTP, queue, KV,
   gRPC, metrics, logger, locks, and future Temporal integration;
4. **Developer experience and observability** — OpenTelemetry, scaffolding,
   generated metadata inspection, worker leak diagnostics, and test helpers.

Each delivery must remain usable and testable without requiring the next one.

## Non-goals

- Replacing RoadRunner's process supervision, worker pool, or IPC;
- adding a Tusk gateway or service mesh;
- implementing a new ORM;
- making Cycle or Spiral a hard dependency of the Tusk core;
- preserving a separate native worker pool inside the Engine;
- adding implicit global state to make persistent workers easier to use.

## Acceptance criteria

The first delivery is complete when:

1. RoadRunner and native adapters execute the same lifecycle event sequence;
2. request-scoped services are reset by one shared lifecycle path;
3. worker and application teardown execute on graceful and exceptional exits;
4. existing `OnStart`/`OnShutdown` applications remain compatible;
5. lifecycle behavior is covered by deterministic unit/integration tests;
6. the compiled container can represent lifecycle hooks without reflection on
   the request hot path;
7. the runtime package documentation explains persistent-safety rules.
