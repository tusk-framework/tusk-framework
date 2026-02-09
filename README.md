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
2. **Persistent by Default**: Built for environments like the **Tusk Engine**, RoadRunner, or Swoole.
3. **Explicit over Magic**: No hidden behavior. Dependencies are pre-compiled and transparent.
4. **Adult PHP**: Leveraging the best of PHP 8.2+ (Readonly, Attributes, Fibers).

---

## Ecosystem Architecture

The Tusk Framework is a monorepo of specialized packages that can be used together or independently:

| Package | Description |
|---------|-------------|
| [**tusk/core**](tusk-core/) | IoC Container and Application Lifecycle. |
| [**tusk/web**](tusk-web/) | Routing, Middleware, and HTTP abstractions. |
| [**tusk/data**](tusk-data/) | Repository Pattern and Database Abstraction. |
| [**tusk/runtime**](tusk-runtime/) | IPC Bridge and Worker Loop implementation. |
| [**tusk/contracts**](tusk-contracts/) | Shared interfaces and base abstractions. |
| [**tusk/security**](tusk-security/) | Authentication and Authorization toolkit. |
| [**tusk/cloud**](tusk-cloud/) | Resilience (Circuit Breakers) and Discovery. |
| [**tusk/cli**](tusk-cli/) | Scaffolding and developer tooling. |

---

## Getting Started

Since Tusk is designed for persistent runtimes, its entry point is a **Worker Loop**.

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

## The Runtime Companion

Although the Framework can run on standard servers, it achieves **Maximum Performance** when paired with the [**Tusk Engine**](https://github.com/tusk-framework/tusk-engine).

- **Go-Powered**: A native master process handles the HTTP stack.
- **NDJSON Protocol**: Efficient communication between Go and PHP.
- **Unified DX**: The `tusk` binary orchestrates both the engine and these framework components.

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
