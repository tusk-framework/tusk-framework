# Tusk Adapter Pattern Technical Spec

## Philosophy
Adapters are implementation details. The application domain depends on **Interfaces** (Contracts), and the **Runtime/Container** provides the **Adapter** implementation at boot-time.

## Key Rules
1. **No Infrastructure in Domain**: No PDO, No Guzzle, No AMQP in `src/Domain`.
2. **Interface Driven**: Domain defines the contract; the runtime implements the contract.
3. **RoadRunner Boundary**: Application traffic enters through the Engine-managed RoadRunner worker. The Framework does not provide a second HTTP server, worker pool, or transport protocol.

## Structure
Runtime HTTP integration has one supported implementation:

```text
tusk-runtime/
└─ src/
   ├─ Adapters/RoadRunnerAdapter.php
   └─ Modules/RoadRunnerHttpModule.php
```

## Declaration
The runtime adapter is constructed at the application boundary and remains
behind `RuntimeAdapterInterface`.

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
