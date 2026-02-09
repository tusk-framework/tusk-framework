# Tusk Runtime

The **Tusk Runtime** is the PHP-side adapter for the **Tusk Engine (Go)**. It transforms the standard request-response cycle into a high-performance persistent process.

## Features
- **IPC Loop**: Efficient NDJSON communication with the Go Engine.
- **Worker Management**: PSR-compliant request handling within a long-lived process.
- **Kernel Bridge**: Seamlessly connects the engine to the Tusk application kernel.

## Installation
Included by default with the Tusk Engine environment.
