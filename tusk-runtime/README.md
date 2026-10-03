# Tusk Runtime

The **Tusk Runtime** is the PHP-side integration layer for RoadRunner persistent workers. The legacy native NDJSON loop is retained only as a migration boundary while the Tusk Engine moves its control-plane capabilities above RoadRunner.

## Features
- **Server Adapter**: RoadRunner and its PSR-7 worker protocol.
- **Worker Management**: PSR-compliant request handling within a long-lived process.
- **Kernel Bridge**: Seamlessly connects the application server to the Tusk application kernel.
- **Runtime Modules**: HTTP and gRPC worker boundaries plus lifecycle-aware capability modules.
- **Provider-neutral capabilities**: Jobs, KV, distributed locks, metrics, and PSR-3 logging.

## Installation
Included by default with the Tusk Framework.

## RoadRunner

Copy `.rr.yaml.example` to `.rr.yaml`, adjust the worker command and limits, then start RoadRunner:

```bash
cp .rr.yaml.example .rr.yaml
rr serve -c .rr.yaml
```

The worker command must not write human-readable output to `STDOUT`; RoadRunner owns that stream. Tusk's lifecycle manager starts and ends each request, including request-scope cleanup, independently of the selected transport.

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

RoadRunner plugins required by the first-party modules are `jobs`, `kv`, `lock`, `metrics`, and `logger`; the worker must expose `RR_RPC`. A missing plugin or unavailable capability fails with an actionable runtime error.

## Worker modes and ownership

The Tusk Engine remains the Go control plane. It starts, monitors, configures, and stops RoadRunner. The PHP runtime manages application lifecycle and adapters, while RoadRunner owns worker pools, supervision, and Goridge IPC.

HTTP uses the compatibility `RoadRunnerAdapter` backed by `RoadRunnerHttpModule`. `RoadRunnerGrpcModule` owns gRPC service registration without exposing the RoadRunner server to application services. Queue production is separate from consumption: `QueueInterface` never starts a consumer pool, and `JobTaskInterface` is used by a future consumer boundary.

The native NDJSON adapter remains an explicit compatibility path and does not emulate RoadRunner capabilities.

The native adapter is not part of the supported platform path and should not be used for new deployments.
