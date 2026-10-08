# RoadRunner Jobs Consumer Design

## Status

Draft for user review. The architectural direction was approved in conversation on 2026-10-08; this document makes the implementation boundaries and acceptance criteria explicit before planning.

## Goal

Make RoadRunner the sole queue transport and consumer runtime for Tusk applications. Applications dispatch named jobs through Tusk contracts; RoadRunner owns queue drivers, delivery, PHP worker processes, pools, and process supervision; the Framework owns job registration, handler invocation, job lifecycle, and application-level retry policy; the Tusk Engine owns validated RoadRunner configuration and process lifecycle.

After the RoadRunner path is integrated and proven end to end, remove the legacy database-polling queue and `queue:work` command. Do not remove the old consumer before the replacement is usable from an Engine-managed application.

## Context and Current State

- The Framework already has a RoadRunner-backed producer contract at `Tusk\Contracts\Runtime\Capabilities\QueueInterface` and an SDK adapter at `Tusk\Runtime\RoadRunner\RoadRunnerJobs`.
- `JobTaskInterface` currently exposes only acknowledge/retry. `RoadRunnerJobTask` adapts those operations, but it does not expose task identity, name, queue, headers, or payload.
- `RoadRunnerJobsModule` accepts an arbitrary closure and can handle a task, but `RuntimeModuleFactory` does not register it and `Application::runWorker()` always starts the HTTP adapter.
- `RuntimeConfiguration` recognizes `capabilities.jobs` as a producer capability. The runtime spec explicitly says the consumer boundary is future work.
- The legacy `Tusk\Events\Queue\DatabaseQueue` and `Tusk\Cli\Commands\QueueWorkerCommand` form the only complete consumer found. The command polls a database and resolves the queued `job_class`; this is a different queue API from the RoadRunner named-message contract.
- The Engine currently projects an HTTP-focused RoadRunner configuration. It does not expose `jobs.consume` or pipeline/driver configuration in its strict project configuration.

The official RoadRunner Jobs SDK provides `Consumer::waitTask()` and received-task operations for ack, nack, and requeue. RoadRunner workers receive their plugin mode through `RR_MODE`; the Engine must configure the Jobs plugin while preserving the existing HTTP mode. RoadRunner configuration, not Tusk PHP, remains responsible for queue drivers and worker-pool mechanics.

## Design Principles

1. **RoadRunner owns transport and delivery.** Tusk does not poll a database, spawn a queue process pool, or implement another queue protocol.
2. **One Engine-managed RoadRunner lifecycle.** HTTP and Jobs are RoadRunner worker modes using the Engine-generated worker entry point; the Engine does not create a second supervisor.
3. **Provider-neutral application API.** Application handlers use Tusk contracts and values, not `Spiral\RoadRunner\Jobs` types.
4. **Named, registered handlers only.** A queued name resolves through a compiled/validated Tusk registry. Never instantiate an arbitrary class named by a message.
5. **Explicit message format.** Job payloads are strings on the queue contract and use JSON as the documented interoperable format. Tusk does not silently hydrate arbitrary PHP objects.
6. **At-least-once semantics are explicit.** Handlers must be idempotent or otherwise safe for redelivery. Ack follows successful handler completion; failures follow a bounded, configured retry policy.
7. **Per-job isolation and observability.** Job hooks, scoped-service reset, logs, metrics, and traces are applied to each delivery without leaking request or prior-job state.
8. **Remove legacy only after proof.** The old queue and command stay until the Engine/Framework integration test demonstrates dispatch, consumption, ack, retry, and shutdown.

## Target Architecture

```text
Application producer
  -> Tusk QueueInterface
  -> RoadRunner Jobs SDK / RPC
  -> RoadRunner configured pipeline and consumer pool
  -> Engine-generated PHP worker (RR_MODE=jobs)
  -> Tusk JobHandlerRegistry
  -> handler + per-job lifecycle/scope
  -> ack or bounded retry/nack
```

The same generated worker entry point dispatches by RoadRunner mode:

```text
RR_MODE=http  -> existing RoadRunner HTTP adapter -> request lifecycle
RR_MODE=jobs  -> RoadRunner Jobs consumer          -> job lifecycle
other         -> actionable startup failure
```

RoadRunner selects and supervises the worker pool. The PHP runtime blocks in the SDK consumer API for the selected Jobs worker; this is the protocol boundary, not a Tusk-owned process pool.

## Application API

### Job registration

Jobs are application services implementing a provider-neutral handler contract and registered under a stable message name, for example:

```php
#[AsJob('orders.send-invoice')]
final class SendInvoice implements JobHandlerInterface
{
    public function __construct(private InvoiceSender $sender) {}

    public function handle(JobContext $job): void
    {
        $payload = $job->jsonPayload();
        $this->sender->send($payload['invoice_id']);
    }
}
```

The exact method and value-object names are implementation-plan details, but the public behavior is fixed:

- a job has a stable non-empty name independent of its PHP class name;
- registration is discoverable/compilable with duplicate and invalid names rejected during boot;
- the handler is resolved from the application container, preserving dependency injection;
- the context exposes immutable message ID, queue, name, raw payload, and string headers;
- JSON decoding is explicit and throws a clear payload error; arbitrary object deserialization is out of scope.

### Producer API

The existing RoadRunner producer contract remains the dispatch boundary:

```php
$queue->dispatch('default', 'orders.send-invoice', json_encode(['invoice_id' => 123], JSON_THROW_ON_ERROR));
```

The Framework may add a small typed helper later, but this migration does not require a second producer API or class-name-based dispatch.

### Acknowledgement and failures

- A handler returning normally triggers one ack request after successful completion. Because delivery is at least once, a process/network failure around ack may redeliver the message; handlers must remain idempotent.
- A handler exception is logged with safe message metadata and is not acknowledged as success.
- Retry attempts and delay are bounded and configurable. Attempt state is carried in RoadRunner task headers, using the SDK's supported requeue/delay operations.
- When the retry limit is reached, the task is negatively acknowledged without requeue. Driver-specific retention/dead-letter routing remains RoadRunner pipeline configuration and must be documented; Tusk must not claim all drivers provide a dead-letter queue.
- Malformed payloads and unknown job names are classified as non-retryable by default to avoid poison-message loops. Operators receive a sanitized actionable log entry.
- If ack/requeue itself fails, the original handler exception remains primary and the delivery failure is logged as secondary; worker health/readiness must reflect a fatal consumer boundary failure where appropriate.

## Lifecycle, Scope, and Observability

Application and worker lifecycle hooks run once per RoadRunner worker as they do for HTTP. Each received job gets a distinct job lifecycle boundary:

```text
application.start -> worker.start -> (job.start -> resolve/handle -> ack|retry -> job.end/reset)*
worker.stop -> application.stop
```

- Add job-start/job-end lifecycle events or an equivalent runtime boundary without routing jobs through HTTP request hooks.
- Reset a dedicated `job` scope in a `finally` path after every delivery. Job cleanup must run even when payload parsing, handler resolution, handler execution, ack, or retry fails.
- Preserve the primary handler/consumer exception if cleanup also fails; report secondary failures through the established logger/observability path.
- Reuse existing `RuntimeObservability` job counters/durations, adding bounded-cardinality attributes only. Do not attach raw payloads, arbitrary headers, credentials, or exception traces to telemetry.
- RoadRunner/Engine owns worker shutdown and recycling. A stopped Jobs channel ends the worker loop and triggers normal Tusk worker/application teardown.

## Engine and RoadRunner Configuration

The Engine remains the only owner of the generated RoadRunner configuration. Extend the Engine's typed project configuration with a Jobs section that can express:

- the list of pipelines the RoadRunner consumer should consume;
- each pipeline's driver and driver-specific configuration;
- safe defaults and validation for required fields, identifiers, and incompatible settings.

The Engine projects these values to RoadRunner's `jobs.consume` and `jobs.pipelines` sections in its generated runtime configuration. HTTP remains unchanged. Pipeline secret values use RoadRunner environment expansion (for example, `${QUEUE_PASSWORD}`); the Engine validates and preserves the reference without expanding it, and never copies secret values into logs, diagnostics, or control-plane metadata. The user-authored `.rr.yaml` remains untouched; Engine-managed generated configuration stays in its existing private runtime location.

Jobs are enabled only when at least one valid pipeline is configured. Configuration validation must reject a consume name without a pipeline, malformed driver configuration, duplicate/invalid names, or unsafe secret interpolation before RoadRunner starts. Existing applications without Jobs configuration retain the current HTTP behavior.

The Framework application config selects/validates the `jobs` worker mode and handler registry; it does not duplicate RoadRunner driver, pipeline, prefetch, or pool configuration. The Engine's `jobs.consume` list and Framework registry are deliberately separate responsibilities: a pipeline decides which messages are delivered; the handler registry decides which named application job handles a message.

## Package and Legacy Removal

After the coordinated integration acceptance criteria pass:

- remove `Tusk\Cli\Commands\QueueWorkerCommand` and the `queue:work` command registration;
- remove `Tusk\Events\Queue\QueueInterface` and `DatabaseQueue` if no other supported consumer/producer path depends on them;
- remove their queue-specific tests and any now-unused schema/DB dependencies;
- preserve `Tusk\Contracts\Runtime\Capabilities\QueueInterface`, `QueueMessageInterface`, and the RoadRunner producer adapter;
- update package READMEs and the root README to show named-job dispatch and RoadRunner pipeline configuration;
- add a migration note explaining that old class-name/array-payload database jobs become named jobs with explicit JSON payloads.

This is a breaking change to the old `queue:work` and `Tusk\Events\Queue` surface. The project is pre-1.0; publish the change with an explicit compatibility note and confirm the supported release policy before release.

## Coordinated Repository Boundaries

### `tusk-framework`

- Provider-neutral job handler/context/registry contracts and `#[AsJob]` discovery/compilation.
- RoadRunner Jobs consumer adapter and `RR_MODE` worker dispatch.
- Per-job lifecycle/scope/observability and error/retry behavior.
- Framework contract, adapter, lifecycle, generated-worker, and test-skeleton coverage.
- Legacy `queue:work`/database queue removal only after integration evidence is available.

### `tusk-engine`

- Strict typed Jobs pipeline configuration in `tusk.json`.
- Deterministic RoadRunner Jobs plugin projection while preserving HTTP and existing runtime configuration.
- Secret-safe configuration errors/diagnostics and validation.
- End-to-end skeleton smoke test: HTTP dispatch -> RoadRunner Jobs consumer -> handler side effect -> clean Engine/RoadRunner shutdown.

The Framework consumer API must be merged/published before the Engine integration PR consumes it. Engine tests must pin the exact Framework commit/branch under the repository's existing cross-repository smoke-test policy. The final legacy-removal PR follows after the two repositories' integrated path passes.

## Verification and Acceptance Criteria

1. A Framework unit/integration test dispatches a named message, resolves exactly the registered handler, and acknowledges it only after success.
2. Failed handlers follow bounded retry/delay behavior; exhausted and malformed/unknown tasks are not silently acknowledged or endlessly requeued.
3. Tests prove each job receives an isolated scope and lifecycle/observability events finish even on failure.
4. `RR_MODE=http` behavior and existing HTTP worker lifecycle remain unchanged; `RR_MODE=jobs` starts the consumer; unsupported modes fail actionably.
5. Engine configuration tests prove deterministic `.rr.yaml` projection, validation of consume/pipeline consistency, secret redaction, and no behavior change for apps without Jobs configuration.
6. A real RoadRunner Engine smoke test proves HTTP dispatch to the configured pipeline, actual consumer execution, success acknowledgement, a retry case, and graceful shutdown with no orphaned processes.
7. Framework and Engine CI, PHPStan, formatting, and Composer/Go checks pass. PHPStan has no `while.alwaysTrue` finding in the removed legacy command because that command is removed only after the new consumer is accepted.
8. The migration guide documents at-least-once delivery, idempotency, JSON payloads, retry limits, and driver-specific failed-message behavior.

## Out of Scope

- Implementing a queue driver in Tusk; RoadRunner owns drivers.
- Creating a Tusk scheduler, queue broker, custom worker pool, or process supervisor.
- Automatic PHP object serialization/deserialization.
- Promising exactly-once delivery or a universal dead-letter queue across RoadRunner drivers.
- Adding Engine-side retries or application handler execution; the Engine only configures and supervises RoadRunner.
- Removing the legacy queue before the RoadRunner consumer and Engine smoke test are usable.

## Sources

- [RoadRunner Jobs PHP SDK](https://github.com/roadrunner-php/jobs): producer, `Consumer::waitTask()`, task acknowledgement, requeue, and delay APIs.
- [RoadRunner worker environment](https://docs.roadrunner.dev/docs/php-worker/environment): worker mode (`RR_MODE`) and RoadRunner-provided runtime environment.
- [RoadRunner Jobs plugin](https://docs.roadrunner.dev/docs/queues-and-jobs/overview-queues): Jobs pipeline/consume configuration and delivery semantics.
