# Tusk Contracts

The **Tusk Contracts** package contains all the core interfaces and abstractions used by the Tusk Framework.

## Purpose
Ensures interoperability between components by defining clear, language-agnostic (where possible) contracts.

## Usage
Implement these interfaces in your own components to integrate with the Tusk ecosystem.

## Runtime capabilities


The runtime contracts are provider-neutral. Application code can depend on Tusk interfaces without importing RoadRunner SDK classes:

- `CapabilityRegistryInterface` resolves explicitly registered capabilities;
- `QueueInterface` and `JobTaskInterface` separate producing jobs from consuming tasks;
- `KeyValueStoreInterface` provides worker-safe key/value operations;
- `LockInterface` provides distributed lock ownership and `withLock` cleanup;
- `MetricsInterface` provides labeled metrics operations;
- `RuntimeModuleInterface` defines deterministic register/start/stop boundaries.

RoadRunner adapters live in `tusk-runtime`. The contracts do not create worker pools, open RPC connections, or prescribe a plugin. A capability that was not declared or provided raises `CapabilityUnavailableException` with remediation guidance.

## Observability contracts

The contracts package also defines the provider-neutral boundary for persistent runtime telemetry:

- TelemetryProviderInterface starts spans and owns exporter flush/shutdown;
- SpanInterface exposes attributes, status, exceptions, and end semantics;
- WorkerDiagnosticsInterface exposes a safe WorkerDiagnosticsSnapshot.

These contracts intentionally do not import OpenTelemetry or RoadRunner types. Applications and the Tusk Engine can consume the same snapshot without exposing request payloads, credentials, headers, cookies, uploads, tokens, or stack traces.
