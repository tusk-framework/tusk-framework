# Tusk Observability and Persistent Worker Diagnostics

Status: Approved design

## Goal

Give Tusk applications first-class observability and persistent-worker diagnostics without coupling framework contracts to a specific telemetry vendor or duplicating responsibilities already owned by RoadRunner.

The feature must make the persistent lifecycle visible, explain worker health, and provide a stable path for OpenTelemetry export. It must work deterministically in tests without starting RoadRunner or requiring an external collector.

## Context

Tusk now has a provider-neutral lifecycle manager and RoadRunner capability modules. Long-lived PHP changes the operational model: state survives between requests, failures can accumulate inside a worker, and a healthy transport does not necessarily mean a healthy application worker.

The observability layer must therefore attach to lifecycle boundaries rather than to a particular HTTP implementation. RoadRunner remains responsible for worker supervision and transport. Tusk owns application lifecycle instrumentation, request/job context, framework metrics, and diagnostics collected from the PHP worker.

## Design principles

1. **Contracts remain provider-neutral.** `tusk-contracts` must not import OpenTelemetry SDK classes, exporters, RoadRunner types, or vendor-specific resource objects.
2. **Lifecycle is the source of truth.** Application, worker, request, and job instrumentation is attached to the existing lifecycle manager and capability execution boundaries.
3. **No duplicate runtime.** Tusk does not replace RoadRunner supervision, worker recycling, IPC, or plugin metrics.
4. **Safe by default.** Telemetry is no-op unless explicitly configured, diagnostics never expose secrets or request bodies, and public errors remain governed by the existing error policy.
5. **Low hot-path cost.** Context and counters are injected through interfaces; reflection and repeated configuration parsing are forbidden on the request path.
6. **Graceful degradation.** Missing exporters or unavailable optional telemetry components must produce a clear startup/configuration error when explicitly enabled, while the default no-op path remains usable.

## Architecture

```text
Tusk lifecycle manager
   ├─ Telemetry manager (provider-neutral)
   │    ├─ No-op telemetry provider
   │    └─ OpenTelemetry bridge/provider
   ├─ Runtime metrics recorder
   └─ Worker diagnostics collector
          ├─ CLI: tusk runtime:diagnostics [--json]
          └─ Engine-facing snapshot contract
```

### Provider-neutral contracts

Add small contracts under `tusk-contracts/src/Observability/`:

- `TelemetryProviderInterface` creates and closes spans and exposes counters/histograms without exposing SDK types.
- `SpanInterface` records attributes, status, exceptions, and child context.
- `MetricsRecorderInterface` records counters and durations.
- `WorkerDiagnosticsInterface` returns a serializable immutable snapshot.
- `WorkerDiagnosticsSnapshot` is a typed value object containing only safe operational fields.

The exact methods must remain intentionally small. The contracts represent the behavior Tusk needs, not the complete OpenTelemetry API. Vendor-specific features remain available inside adapters.

### OpenTelemetry integration

Create a separate runtime integration boundary rather than importing OpenTelemetry into every package. The bridge maps Tusk spans and metrics to OpenTelemetry PHP SDK objects and follows OpenTelemetry semantic conventions where they apply:

- `tusk.application.start`
- `tusk.worker.start`
- `tusk.http.server`
- `tusk.job.process`
- `tusk.worker.stop`

The bridge must support OTLP configuration through the existing application configuration/environment mechanism. Exporter creation, resource attributes, service name, sampling, and endpoint parsing belong to the integration package. Framework contracts only receive normalized names, attributes, status, and durations.

The default provider is a no-op implementation. Applications that enable OpenTelemetry must receive an explicit configuration error if the integration package or required exporter configuration is unavailable; silently claiming that telemetry is active is not acceptable.

### Lifecycle instrumentation

The telemetry manager is registered once per application and observes the shared lifecycle manager:

1. `application.start` creates application metadata and initializes the provider.
2. `worker.start` creates a worker context and resets per-worker counters.
3. `request.start` creates a request span and records runtime/route metadata when available.
4. `request.end` records status, duration, exception state, and closes the request span in a `finally` path.
5. `job.start`/`job.end` use the same context mechanism when queue processing is enabled.
6. `worker.stop` flushes provider data when supported and captures the final worker snapshot.
7. `application.stop` shuts the provider down after all worker teardown has completed.

The implementation must preserve the original application exception if instrumentation or telemetry export fails during request processing. Export failures are logged and counted, but must not turn a successful request into a failed request.

### Diagnostics snapshot

`WorkerDiagnosticsSnapshot` exposes safe operational data:

- worker identifier and runtime adapter name;
- process start time and uptime;
- lifecycle state;
- request and job totals, successes, failures, and in-flight count;
- duration aggregates sufficient for average and worst observed duration;
- current and peak memory usage;
- request-scope reset count and detected cleanup anomalies;
- telemetry export failures;
- last failure timestamp, category, and redacted message.

It must not include authorization headers, cookies, request bodies, uploaded file contents, secrets, tokens, or arbitrary exception traces. Diagnostic categories are stable strings suitable for automation.

The collector is updated by lifecycle events and capability boundaries. It must not scan the heap or inspect arbitrary application objects on every request. Any optional deep inspection belongs to an explicit diagnostic command and is outside this first delivery.

### CLI and Engine boundary

Add a Tusk CLI command:

```text
tusk runtime:diagnostics
tusk runtime:diagnostics --json
```

The human output is concise and operational; JSON is stable and machine-readable. The command consumes `WorkerDiagnosticsInterface` and does not create a second diagnostics model.

The same snapshot contract is suitable for a future Tusk Engine control-plane request. This delivery defines the contract and framework-side producer; Engine transport and authorization integration are a follow-up implementation that must reuse this snapshot rather than inventing a second schema.

## Configuration

Configuration is resolved once during application startup. The baseline settings are:

- `observability.enabled`: false by default;
- `observability.service_name`: application name when omitted;
- `observability.exporter`: `none` or `otlp`;
- `observability.otlp.endpoint`: required when OTLP is enabled;
- `observability.sample_ratio`: a value from `0.0` through `1.0`;
- `observability.resource`: safe static resource attributes;
- `observability.diagnostics`: enabled by default locally and configurable for production.

Invalid values fail before the runtime starts. Secrets and credentials must be passed through the existing secret/environment mechanisms and must never appear in diagnostics or configuration dumps.

## Failure handling

- A telemetry provider cannot replace the application exception.
- Exporter failures are recorded in diagnostics and sent to the existing logger.
- Provider shutdown attempts all cleanup and reports the first failure after cleanup, matching lifecycle semantics.
- A malformed diagnostic snapshot is a programming/configuration error and fails tests; it must not be silently truncated.
- A CLI diagnostics request that cannot access a worker reports a structured unavailable state instead of inventing stale health data.

## Testing strategy

Tests must cover behavior with real Tusk objects and small fakes:

- no-op provider has zero observable overhead and never throws;
- lifecycle events create/close the correct application, worker, request, and job spans;
- request and job failures record status and preserve the original exception;
- telemetry export failure does not fail the request;
- counters, durations, memory values, and reset anomalies appear in snapshots;
- snapshots exclude credentials, tokens, bodies, and traces;
- configuration validation rejects invalid sampling and incomplete OTLP settings;
- CLI human and JSON output consume the same snapshot contract;
- repeated start/stop and failed shutdown remain idempotent;
- provider-neutral packages do not import OpenTelemetry classes;
- no live RoadRunner daemon or external collector is required.

## Rollout and compatibility

- Existing applications remain unchanged with the default no-op provider.
- Existing lifecycle hooks and runtime adapters continue to work.
- RoadRunner is the supported production runtime and owns the diagnostics boundary.
- The OpenTelemetry bridge is additive and can be enabled per application.
- The Engine can adopt the snapshot contract later without changing PHP application code.

## Non-goals

- Replacing RoadRunner metrics, supervision, recycling, or health checks.
- Building a gateway, sidecar, service mesh, or distributed tracing proxy.
- Exposing raw request payloads or arbitrary heap dumps.
- Making OpenTelemetry SDK classes part of Tusk's public contracts.
- Implementing Engine transport/authentication for diagnostics in this delivery.
- Adding automatic leak remediation or worker restart policy; RoadRunner remains responsible for restart decisions.

## Acceptance criteria

1. Tusk has provider-neutral telemetry and diagnostics contracts.
2. Request/job lifecycle spans and metrics are generated from shared lifecycle boundaries.
3. OpenTelemetry OTLP export works through an isolated bridge when enabled.
4. The default no-op path preserves current applications and test behavior.
5. `tusk runtime:diagnostics` and `--json` expose the same safe snapshot model.
6. Worker snapshots contain actionable runtime health data without secrets or payloads.
7. Telemetry failures never replace application failures.
8. Tests prove behavior without RoadRunner or an external collector.
