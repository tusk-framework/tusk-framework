# Tusk Framework

> **Domain-first PHP ecosystem for high-performance, persistent applications.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![Documentation](https://img.shields.io/badge/docs-tusk--framework.github.io-green.svg)](https://tusk-framework.github.io/tusk-docs/)

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

An application can select modules from its bootstrap:

```php
return [
    'runtime' => [
        'adapter' => 'roadrunner',
        'modules' => ['http', 'capabilities.kv', 'capabilities.metrics'],
    ],
];
```

RoadRunner drivers, endpoints, pool limits, TLS, and logger output stay in `.rr.yaml`; `RR_RPC` is provided by the RoadRunner worker. The native adapter remains an explicit compatibility backend and does not emulate these capabilities.

---

## Getting Started

Since Tusk is designed for persistent runtimes, its supported entry point is a **RoadRunner worker loop**. The framework keeps request-scoped state isolated; the legacy native loop is retained only as a migration boundary.

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

---

## The Compiler Companion

Tusk achieves **Maximum Performance** using its CLI compiler:

- **Ahead-Of-Time (AOT)**: Generates static `.tusk` files with raw PHP instructions.
- **Zero-Reflection**: At runtime, there are no heavy reflection calls.
- **Unified DX**: The `bin/tusk build` binary orchestrates the compilation of DI, Routes, and Commands.

---

## License

Tusk Framework is open-source software licensed under the [MIT License](LICENSE).

---

<div align="center">
  <b>Built for developers who want more from PHP.</b><br>
  <a href="https://tusk-framework.github.io/tusk-docs/">Website</a> • 
  <a href="https://tusk-framework.github.io/tusk-docs/">Documentation</a> • 
  <a href="https://github.com/tusk-framework">GitHub</a>
</div>
