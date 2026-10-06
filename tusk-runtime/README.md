# Tusk Runtime

The **Tusk Runtime** is the PHP-side integration layer for RoadRunner persistent workers. The generated application bootstrap composes the application once; RoadRunner owns the PHP worker channel and transport lifecycle, while the Tusk Engine owns the generated `.tusk/runtime/worker.php` control-plane entry point.

## Features

- **Server Adapter**: RoadRunner and its PSR-7 worker protocol.
- **Worker Management**: PSR-compliant request handling within a long-lived process.
- **Kernel Bridge**: Connects the application server to the Tusk application kernel.
- **Runtime Modules**: HTTP and gRPC worker boundaries plus lifecycle-aware capability modules.
- **Provider-neutral capabilities**: Jobs, KV, distributed locks, metrics, and PSR-3 logging.
- **Observability**: Provider-neutral lifecycle telemetry, OpenTelemetry OTLP export, and safe persistent-worker diagnostics.

## Installation

Included by default with the Tusk Framework.

## RoadRunner

Copy `.rr.yaml.example` to `.rr.yaml`, adjust the worker command and limits, then start RoadRunner through the Tusk Engine:

```bash
cp .rr.yaml.example .rr.yaml
tusk start
```

RoadRunner owns the worker transport and process channel. The Tusk Runtime does not implement a second application transport; application output must not corrupt the RoadRunner channel. Tusk's lifecycle manager starts and ends each request, including request-scope cleanup.

The generated PHP worker loads `bootstrap/app.php` once and calls `runWorker()`. `Application::handle()` remains the request boundary. Runtime-generated files stay under `.tusk`; application code remains in `bootstrap/`, `config/`, and `routes/`.

## Runtime configuration

The application bootstrap may return a configuration array. Existing bootstraps that return `null` or no value keep the default RoadRunner HTTP runtime:

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

Tusk's array only selects framework modules. RoadRunner remains the source of truth for drivers, queues, KV storage names, pools, endpoints, TLS, and logging output in `.rr.yaml`.

Selected capability services are exposed through Tusk contracts such as `QueueInterface`, `KeyValueStoreInterface`, `LockInterface`, `MetricsInterface`, and `Psr\Log\LoggerInterface`. The runtime creates one shared `RR_RPC` connection per worker and caches each capability for that worker.

## Observability

The default provider is no-op. Enable OpenTelemetry with the application bootstrap configuration:

```php
return [
    'observability' => [
        'enabled' => true,
        'service_name' => 'orders',
        'exporter' => 'otlp',
        'otlp' => ['endpoint' => 'https://otel-collector.example/v1/traces'],
        'sample_ratio' => 0.25,
        'resource' => ['deployment.environment' => 'production'],
    ],
];
```

The endpoint must be an absolute HTTP(S) URL and `sample_ratio` must be between `0.0` and `1.0`. Configuration is validated before the runtime starts. OTLP export uses HTTP; no gRPC PHP extension is required by Tusk's first integration.

Lifecycle instrumentation creates spans for application, worker, HTTP request, and queue job boundaries, and records request/job counters and durations. Exporter failures are logged/countable and never replace an application exception.

Use the CLI command for a local snapshot:

```bash
bin/tusk runtime:diagnostics
bin/tusk runtime:diagnostics --json
```

The command reports the current process and explicitly does not claim to query a remote worker. The Tusk Engine can consume the same `WorkerDiagnosticsSnapshot` contract when its control-plane diagnostics request is implemented.

RoadRunner plugins required by the first-party modules are `jobs`, `kv`, `lock`, `metrics`, and `logger`; the worker must expose `RR_RPC`. A missing plugin or unavailable capability fails with an actionable runtime error.

## Worker modes and ownership

The Tusk Engine remains the Go control plane. It generates and validates runtime configuration, starts, monitors, configures, and stops RoadRunner, and performs graceful reload/stop. RoadRunner owns worker pools, supervision, recycling, and Goridge IPC. The PHP runtime manages application lifecycle and adapters; it does not create a second worker pool or proxy.

HTTP uses `RoadRunnerAdapter` backed by `RoadRunnerHttpModule`. `RoadRunnerGrpcModule` owns gRPC service registration without exposing the RoadRunner server to application services. Queue production is separate from consumption: `QueueInterface` never starts a consumer pool, and `JobTaskInterface` is used by a future consumer boundary.

RoadRunner is the only supported runtime adapter. Unsupported legacy runtime selections are rejected with an actionable error directing applications to `roadrunner`.
