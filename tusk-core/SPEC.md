# Tusk Core: IoC Container Technical Spec

## Overview
The Tusk IoC Container is the backbone of the framework, optimized for a persistent runtime. Unlike traditional PHP containers that are rebuilt every request, the Tusk container is **persistent** and **compiled**.

## Core Concepts

### 1. Service Attributes
We use PHP 8 attributes to define services and their metadata.

- `#[Service]`: Marks a class as a container-managed service.
- `#[Singleton]`: Default scope; one instance per application lifecycle.
- `#[Scope('worker')]`: One instance per worker process.
- `#[Inject]`: Explicit dependency injection (optional if constructor injection is used).

### 2. Compilation Strategy
To achieve near-zero performance overhead:
- **Build Phase**: Scan class files for attributes.
- **Generation Phase**: Generate a standalone PHP class (e.g., `CompiledContainer.php`) that instantiates services using factory methods.
- **Runtime Phase**: Load the `CompiledContainer`.

### 3. Application Lifecycle Hooks
Services can hook into the application lifecycle:
- `#[OnStart]`: Executed after the container is fully initialized.
- `#[OnShutdown]`: Executed during graceful shutdown.

## Example Service Implementation

```php
#[Service]
class OrderProcessor {
    public function __construct(
        private Repository $repo,
        #[Value('orders.batch_size')]
        private int $batchSize
    ) {}

    #[OnStart]
    public function initialize(): void {
        // Init logic
    }
}
```

## Container API (Contract)

```php
interface ContainerInterface {
    public function get(string $id): object;
    public function has(string $id): bool;
    public function runHooks(string $hookAttribute): void;
}
```

## Failure Mode
The container MUST fail at **boot-time** if:
- A circular dependency is detected.
- A required dependency is missing.
- A configuration value (`#[Value]`) is missing.

---
*Status: Draft v0.1*
