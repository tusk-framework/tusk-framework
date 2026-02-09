# Tusk Adapter Pattern Technical Spec

## Philosophy
Adapters are implementation details. The application domain depends on **Interfaces** (Contracts), and the **Runtime/Container** provides the **Adapter** implementation at boot-time.

## Key Rules
1. **No Infrastructure in Domain**: No PDO, No Guzzle, No AMQP in `src/Domain`.
2. **Interface Driven**: Domain defines the contract; Adapter implements it.
3. **Config-Driven Selection**: If multiple adapters exist for an interface (e.g., `MemoryRepository` vs `SqlRepository`), the selection is made via configuration, not code change.

## Structure
Each module (e.g., `tusk-web`, `tusk-data`) will have a standard structure:

```text
tusk-web/
├─ src/
│  ├─ Http/           (The abstraction)
│  ├─ Adapters/       (The implementations)
│  │  ├─ RoadRunner/
│  │  ├─ Swoole/
│  │  └─ Native/
```

## Declaration
Adapters are declared as services but can be tagged for discovery.

```php
#[Service]
class RoadRunnerHttpAdapter implements HttpAdapterInterface 
{
    // ...
}
```

## Lifecycle
Adapters are usually **Singletons** and participate in the `#[OnStart]` and `#[OnShutdown]` hooks to manage resource connections (Socket setup, DB connections).

---
*Status: Draft v0.1*
