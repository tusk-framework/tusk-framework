# Tusk Meta-Package

The `tusk/framework` package is a meta-package that aggregates the core components of the Tusk Framework ecosystem. It provides a convenient entry point for developers starting a new project.

## Installation

```bash
composer require tusk/framework
```

## What's Included?

By requiring this package, you automatically get:

- **tusk/core**: The IoC Container and Dependency Injection system.
- **tusk/runtime**: The Persistent Process Runner and Application Kernel.
- **tusk/web**: The HTTP layer and routing system.

## Optional Modules

You may also want to install:

- **tusk/data**: Persistence layer (ORM/DB).
- **tusk/observe**: Observability tools (Metrics/Tracing).

## Documentation

- [Manifesto](../MANIFESTO.md)
- [Roadmap](../ROADMAP.md)

## License

MIT License.

To build a Tusk application, you define your domain logic as services:

```php
#[Service]
    public function __construct(
        private Gateway $gateway
    ) {}
}
```

Then start your persistent runtime:

```bash
tusk run app.php
```

## 🧩 Optional Modules

- `tusk/data`: Repositories and SQL/NoSQL support.
- `tusk/messaging`: Async event handling (Kafka, RabbitMQ).
- `tusk/security`: Declarative AuthN/AuthZ.
- `tusk/observe`: OpenTelemetry & Metrics.

---
*For the full vision, read the [Tusk Manifesto](../MANIFESTO.md).*
