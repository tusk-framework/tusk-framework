# Tusk Runtime

The **Tusk Runtime** is the PHP-side integration layer for RoadRunner persistent workers. The legacy native NDJSON loop is retained only as a migration boundary while the Tusk Engine moves its control-plane capabilities above RoadRunner.

## Features
- **Server Adapter**: RoadRunner and its PSR-7 worker protocol.
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

The native adapter is not part of the supported platform path and should not be used for new deployments.
