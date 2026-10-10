# Tusk Framework

> **Domain-first PHP ecosystem for high-performance, persistent applications.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![Documentation](https://img.shields.io/badge/docs-tusk--framework.github.io-green.svg)](https://tusk-framework.github.io/tusk-docs/)

[Contributing](CONTRIBUTING.md) · [Code of Conduct](CODE_OF_CONDUCT.md)

---

## What is Tusk Framework?

Tusk is a collection of modular PHP components designed for **Long-lived Applications**. It moves away from the traditional "boot-and-die" PHP lifecycle, allowing you to build domain-driven systems that stay in memory, maintaining state, connection pools, and pre-compiled containers.

While typical frameworks focus on the "Web", Tusk focuses on your **Domain**.

### Core Philosophy
1. **Domain-First**: Your code describes business rules, not framework boilerplate.
2. **Zero-Reflection**: We compile your Container, Routes, and Events ahead of time. At runtime, the framework is incredibly fast and static.
3. **Explicit over Magic**: No hidden behavior. Dependencies are pre-compiled and transparent.
4. **Adult PHP**: Leveraging the best of PHP 8.2+ (Readonly, Attributes, Native Types).

---

## Ecosystem Architecture

The Tusk Framework is a monorepo of specialized packages that can be used together or independently:

| Package | Description |
|---------|-------------|
| [**tusk/core**](tusk-core/) | IoC Container and Application Lifecycle. |
| [**tusk/web**](tusk-web/) | Routing, Middleware, and HTTP abstractions. |
| [**tusk/data**](tusk-data/) | Repository Pattern and Database Abstraction. |
| [**tusk/runtime**](tusk-runtime/) | RoadRunner persistent worker integration. |
| [**tusk/contracts**](tusk-contracts/) | Shared interfaces and base abstractions. |
| [**tusk/security**](tusk-security/) | Authentication and Authorization toolkit. |
| [**tusk/cloud**](tusk-cloud/) | Resilience (Circuit Breakers) and Discovery. |
| [**tusk/cli**](tusk-cli/) | Scaffolding and developer tooling. |

### RoadRunner capability matrix

Tusk keeps application code on stable contracts while RoadRunner remains responsible for worker pools, supervision, and Goridge IPC:

| Tusk module | Contract | RoadRunner plugin |
| --- | --- | --- |
| `capabilities.jobs` | `QueueInterface` | `jobs` |
| `capabilities.kv` | `KeyValueStoreInterface` | `kv` |
| `capabilities.lock` | `LockInterface` | `lock` |
| `capabilities.metrics` | `MetricsInterface` | `metrics` |
| `capabilities.logger` | `Psr\Log\LoggerInterface` | `logger` |
| `grpc` | gRPC service registry | gRPC worker mode |

The Go `tusk-engine` is the control plane above RoadRunner. It owns configuration validation, process lifecycle, health, logs, metrics, and graceful stop; it does not duplicate RoadRunner's pools or IPC. The PHP runtime owns application contracts, dependency injection, and lifecycle hooks.

| Concern | Owner |
| --- | --- |
| `bootstrap/app.php`, application configuration, handlers, and lifecycle hooks | Tusk Framework application |
| `.tusk/runtime/worker.php`, startup validation, process supervision, and control-plane diagnostics | Tusk Engine |
| HTTP transport, Goridge IPC, worker pools, recycling, and process-level shutdown | RoadRunner |

An application can select modules from its bootstrap:

```php
return [
    'runtime' => [
        'adapter' => 'roadrunner',
        'modules' => ['http', 'capabilities.kv', 'capabilities.metrics'],
    ],
];
```

RoadRunner drivers, endpoints, pool limits, TLS, and logger output stay in `.rr.yaml`; `RR_RPC` is provided by the RoadRunner worker. RoadRunner is the sole Framework runtime boundary; the Engine owns the generated worker and process lifecycle.

### Observability and worker diagnostics

Observability is disabled by default and uses a no-op provider until explicitly enabled. Tusk instruments application, worker, request, and job lifecycle boundaries and can export traces and metrics through the OpenTelemetry OTLP HTTP exporter:

```php
return [
    'observability' => [
        'enabled' => true,
        'service_name' => 'orders',
        'exporter' => 'otlp',
        'otlp' => ['endpoint' => 'https://otel-collector.example/v1/traces'],
        'sample_ratio' => 0.25,
    ],
];
```

The snapshot intentionally excludes headers, cookies, bodies, uploads, secrets, tokens, and exception traces. Run `tusk runtime:diagnostics` for a local human-readable snapshot or `tusk runtime:diagnostics --json` for the stable machine-readable schema. The CLI reports the current process; remote worker health is a Tusk Engine control-plane concern.

---

## Getting Started

Since Tusk is designed for persistent runtimes, its supported entry point is a **RoadRunner worker loop**. Applications provide `bootstrap/app.php`; the Engine generates `.tusk/runtime/worker.php`, and the Framework keeps request-scoped state isolated for each request.

### 1. Installation
```bash
composer require tusk/framework
```

### 2. The Logic Layer
Tusk separates the **Runtime** from the **Domain**. Your application code lives inside the Framework layer:

```php
#[Controller('/users')]
class UserController {
    public function __construct(
        private UserRepository $users
    ) {}

    #[Get]
    public function list() {
        return Response::json($this->users->all());
    }
}
```

For a complete runnable example covering typed CRUD routes, constructor
injection, validation, Problem Details, and the PSR-7 escape hatch, see the
[Typed HTTP CRUD guide](docs/guides/typed-http-crud.md).

### Versioned database migrations

Generated projects include first-party Doctrine migration commands that work
without `tusk build`. Use `make:migration`, `migrate:status`, and `migrate` for
reviewed versioned schema changes; production execution requires
`--allow-production`. The meaning of `migrate` changed from direct SchemaTool
synchronization to versioned migrations. The old local-only operation is now
`schema:sync --force`, and existing databases are never baselined automatically.
See the [database migrations guide](docs/database-migrations.md) for dry-runs,
SQL export, rollback, production safeguards, and existing-database adoption.

---

## The Compiler Companion

Tusk achieves **Maximum Performance** using its CLI compiler:

- **Ahead-Of-Time (AOT)**: Generates static `.tusk` files with raw PHP instructions.
- **Zero-Reflection**: At runtime, there are no heavy reflection calls.
- **Unified DX**: The `bin/tusk build` binary orchestrates the compilation of DI, Routes, and Commands.

## Contributing and release integrity

See [CONTRIBUTING.md](CONTRIBUTING.md) for the PHP and Composer development
workflow, package boundaries, testing expectations, Conventional Commits, and
pull request guidance. Community participation follows the [Code of Conduct](CODE_OF_CONDUCT.md).

Framework releases are versioned from Conventional Commits and must pass the
PHP compatibility matrix before publication. Release artifacts and provenance
are produced from the reviewed source tree; private signing material is never
committed to the repository.

---

## License

## Engine integration contract

The generated application is designed to run under the Tusk Engine's
RoadRunner control plane. Engine-owned runtime state belongs in `.tusk/` and
is excluded by the generated project's `.gitignore`; the generator does not
modify an existing project directory.

The coordinated skeleton smoke test consumes a published Framework commit
selected by the Engine integration workflow. It verifies the exact Framework
contract and does not provide a legacy runtime fallback.

Tusk Framework is open-source software licensed under the [MIT License](LICENSE).

---

<div align="center">
  <b>Built for developers who want more from PHP.</b><br>
  <a href="https://tusk-framework.github.io/tusk-docs/">Website</a> • 
  <a href="https://tusk-framework.github.io/tusk-docs/">Documentation</a> • 
  <a href="https://github.com/tusk-framework">GitHub</a>
</div>
# Background jobs

Tusk named jobs run in the RoadRunner worker selected by `RR_MODE=jobs`; applications remain in HTTP mode by default. Handlers use `#[AsJob('name')]`, receive JSON object payloads, and should be idempotent because delivery is at least once. Retry bounds default to three total attempts with a one-second delay. Failed-task retention/dead-letter behavior depends on the RoadRunner queue driver.
