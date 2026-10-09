# Framework–Engine Resilience Diagnostics Design

## Context and goal

Framework resilience configuration and circuit-breaker state live inside long-lived PHP workers. Tusk Engine currently owns a versioned control API, but its metadata is assembled from static Go configuration and component descriptors; it cannot truthfully report the PHP workers' active named policies or breaker state.

The goal is to make Engine metadata report a safe, versioned, current-enough summary of Framework resilience without duplicating policy execution, exposing a public application route, or allowing diagnostics failures to affect user operations.

## Chosen architecture

The Framework remains the source of truth. When Engine control metadata is enabled, Engine starts a second, private HTTP listener on IPv4 loopback and an ephemeral port. It creates a cryptographically random per-run bearer token and passes the listener URL and token to RoadRunner through the child process environment. The private listener accepts only bounded resilience snapshots; it is not mounted on the public control API listener.

The current Framework validates and binds `ResilienceConfiguration`, but the normal application container does not yet bind a configuration-aware pipeline API or collect active policy use. This change adds and binds a worker-local `ResilienceRuntime` facade. Its `pipeline(string $name): ResiliencePipelineBuilder` resolves the effective active-profile policy through the existing resolver and shared factory; its `diagnostics(): ResilienceDiagnosticsSnapshot` returns an immutable, sanitized view. Resolving a pipeline registers its effective policy/features; circuit-breaker transitions update that registry. Existing direct/programmatic construction remains supported and does not implicitly publish Engine diagnostics.

Each PHP worker builds a sanitized snapshot from its validated active-profile `ResilienceConfiguration`, policies resolved through `ResilienceRuntime`, and observed circuit states. A configured circuit starts as `closed` when the worker-local state store confirms it; otherwise its state is `unknown` until observed. On worker bootstrap and after circuit transitions, the Framework reporter sends the complete snapshot to Engine. Completed request/job lifecycle boundaries provide a coalesced heartbeat, allowing Engine to identify stale observations without sending on every request. The reporter has a 50 ms timeout, never retries, and swallows transport failures; it must not replace the business outcome. Runtime uses a generic optional lifecycle checkpoint contract so the Runtime package need not depend on the Cloud package.

Engine validates and stores the latest snapshot per worker in memory, keyed by an opaque worker identifier and monotonically increasing sequence. The worker identifier is generated per PHP process and is not returned in public metadata. Engine rejects malformed, oversized, unauthenticated, out-of-order, or over-capacity reports. Snapshot state is cleared when the managed runtime stops. Reports are never written to disk.

The existing authenticated `/v1/metadata` response gains an additive `application.resilience` object with its own `schema_version: "v1"`. It contains effective policy names and enabled policy kinds, an observation timestamp/freshness status, and per-policy circuit-state counts across reporting workers (`closed`, `open`, `half_open`, `unknown`). It never includes policy thresholds, exception class names, request data, credentials, worker identifiers, or component configuration values. For non-loopback control listeners, policy names are omitted while aggregate state counts remain available. If no Framework report has arrived, the field reports `unavailable`; expired reports contribute `unknown` rather than being misrepresented as live state.

If Engine's control API is disabled, Engine does not create the listener or inject diagnostic credentials and Framework diagnostics remain disabled. Existing metadata fields and their `v1` contract remain backward compatible; the new nested object is independently versioned.

## Alternatives considered

- **Engine scrapes a Framework HTTP route:** rejected because it makes diagnostics depend on public route registration, reverse-proxy behavior, application readiness, and route-level access control.
- **Workers write a shared JSON file:** rejected because concurrent workers require locking/atomic replacement, crashed workers leave ambiguous records, and the file becomes a second cross-process protocol with disk lifecycle concerns.
- **Framework pushes to a private Engine loopback listener:** selected because the Engine owns runtime startup and can provide an ephemeral local address and secret without exposing a new app route. Framework remains authoritative for PHP state and Engine only aggregates/report it.

## Contract

### Framework-to-Engine report

`POST /internal/v1/resilience/snapshot` on the private listener. Requests use `Authorization: Bearer <per-run-token>` and `Content-Type: application/json`.

```json
{
  "schema_version": "v1",
  "worker_id": "opaque-random-per-process-id",
  "sequence": 12,
  "policies": [
    {"name": "payments", "features": ["retry", "circuit_breaker"]}
  ],
  "circuits": [
    {"name": "payments", "state": "open"}
  ]
}
```

Only policy names resolved through `ResilienceRuntime` and their enabled feature names are sent. Values/settings are excluded. Circuit states are limited to `closed`, `open`, `half_open`, and `unknown`. The Engine records receipt time itself; it does not trust worker clocks. A strictly increasing sequence makes concurrent/retried deliveries safe. The endpoint returns a small success response and does not echo submitted values.

The report is a full replacement for one worker, not a patch. The implementation defines explicit upper bounds of 64 KiB per request, 256 policy entries and 256 circuit entries per report, 128 UTF-8 bytes per name, and 4,096 retained workers. Names must be non-empty identifiers and are documented as public diagnostic labels, not secret storage. The Framework validates those bounds before reporting; the Engine independently enforces them at ingress.

### Engine metadata projection

The metadata response adds:

```json
{
  "application": {
    "resilience": {
      "schema_version": "v1",
      "status": "fresh",
      "observed_at": "2026-10-08T12:00:00Z",
      "policies": [
        {"name": "payments", "features": ["retry", "circuit_breaker"]}
      ],
      "circuits": [
        {"name": "payments", "workers": {"closed": 3, "open": 1, "half_open": 0, "unknown": 0}}
      ]
    }
  }
}
```

`status` is `unavailable` when no worker has reported, `fresh` while at least one current report is within the lease, and `stale` when reports exist but all have exceeded it. A worker becomes `unknown` after the fixed lease (60 seconds) without a report. Aggregation is deterministic and sorted. On non-loopback control listeners, `policies` and circuit names are omitted; only aggregate state counts and status are returned.

## Lifecycle and failure behavior

1. Engine creates the private listener/token before starting RoadRunner, then injects `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL` and `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN` into the RoadRunner process environment. The token is not written to generated `.rr.yaml`, command output, logs, or metadata.
2. A worker initializes the reporter only when both environment values are present and the URL is loopback HTTP. Otherwise it uses a no-op reporter.
3. The worker reports its initial configuration/state and circuit transitions immediately. Request/job completion acts as a coalesced heartbeat, sent at most once per 15 seconds per worker. Each report uses a single 50 ms bounded attempt; failures never go to application output.
4. Engine accepts only the configured listener, constant-time token comparison, bounded JSON, supported schema, valid identifiers/states, and a sequence newer than that worker's last accepted report.
5. Metadata computes a read-only aggregation from immutable copies. A report from one worker cannot erase another worker's current snapshot.
6. Engine stops the private listener and clears snapshots as part of managed runtime shutdown. Startup fails if the private listener cannot bind when diagnostics are enabled; no worker is started with a missing or partially configured reporter channel.

The metadata endpoint remains protected by its existing control API authorization. The private ingest listener is never reachable on a non-loopback address. Diagnostics are best-effort after startup; invalid reports are rejected and counted without logging their body/token.

## Compatibility and migration

- No new PHP package or mandatory telemetry provider is required.
- Existing Framework resilience APIs, Engine component APIs, `/v1/metadata` fields, and RoadRunner HTTP behavior remain intact.
- The new metadata block is additive and independently versioned.
- Projects not started by Tusk Engine continue to use the Framework normally; the reporter is a no-op when Engine does not provide its environment contract.
- No `tusk-engine.yaml` user setting is added for the ephemeral endpoint or token.

## Security and privacy

- Listener binds only to `127.0.0.1` on an OS-assigned port and is independently authenticated with a fresh cryptographic secret per Engine run.
- Token comparison is constant-time; token and request bodies are never logged or returned.
- Request size, identifiers, counts, and accepted sequence are bounded/validated before storage.
- No resilience configuration values, throwable names, request attributes, telemetry attributes, worker PID, or opaque worker ID are exposed in metadata.
- Non-loopback metadata omits policy and circuit identifiers but retains aggregate state.
- Snapshots are process-memory-only and cleared on stop; no diagnostic state is persisted.

## Verification criteria

- Framework unit tests prove `ResilienceRuntime` application binding, resolution/registration of effective policy/features, deterministic sanitized snapshots, actual breaker-state reads, stable worker identity/sequence, and reporter no-op behavior without Engine environment.
- Framework tests prove reporting at bootstrap, transition, request, and job boundaries; timeouts/errors never change an operation result. Direct factory construction without the Tusk application binding remains supported and is not falsely reported as Engine-managed.
- Engine tests prove authentication, loopback-only binding, payload/count limits, schema validation, sequence ordering, worker isolation, deterministic aggregation, lease expiry, and memory cleanup on stop.
- Metadata tests prove additive compatibility, redaction on remote listeners, no secrets/worker IDs, and `unavailable`/`fresh`/`stale` semantics.
- A cross-repository lab test starts the actual Engine-managed RoadRunner application, triggers a breaker transition from closed to open and back through half-open/closed, observes metadata changes, restarts a worker, and confirms expired state becomes unknown.
- All Framework PHP CI versions and Engine Go CI checks pass before merge; both repositories receive separate PRs and are merged Engine-first, then Framework.

## Scope exclusions

- Engine does not execute, configure, or duplicate PHP resilience policies; it only receives bounded diagnostics.
- This does not add Dapr/Istio proxy behavior, external service discovery, remote diagnostics ingestion, custom per-project listeners, or request-path calls to Engine.
- Declarative resilience attributes and compiled metadata remain in Framework issues #11/#12.
- The Engine's own Go service-invocation resilience state remains distinct from Framework PHP worker resilience.
