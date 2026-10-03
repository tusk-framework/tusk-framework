# Tusk Runtime

The **Tusk Runtime** is the PHP-side integration layer for persistent application servers. **RoadRunner is the default runtime**; the native NDJSON loop remains available as an explicit compatibility mode.

## Features
- **Server Adapters**: RoadRunner by default and the native NDJSON loop for compatibility.
- **Worker Management**: PSR-compliant request handling within a long-lived process.
- **Kernel Bridge**: Seamlessly connects the application server to the Tusk application kernel.

## Installation
Included by default with the Tusk Framework.

## RoadRunner

Copy `.rr.yaml.example` to `.rr.yaml`, adjust the worker command and limits, then start RoadRunner:

```bash
cp .rr.yaml.example .rr.yaml
rr serve -c .rr.yaml
```

The worker command must not write human-readable output to `STDOUT`; RoadRunner owns that stream. Tusk sends startup diagnostics to `STDERR` and resets request-scoped services after every request.

For local protocol compatibility, select the native adapter explicitly:

```bash
TUSK_RUNTIME=native vendor/bin/tusk run app.php --runtime=native
```
