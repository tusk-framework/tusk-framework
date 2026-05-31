# Tusk CLI

The **Tusk CLI** provides developer tooling and command-line interfaces for the Tusk Framework. 
It works as the primary build tool to compile the Ahead-Of-Time (AOT) files.

## Responsibilities
- **Generator**: Generate controllers, models, and migrations.
- **Maintenance**: Clear caches, run migrations.
- **Tooling**: Diagnosis and setup utilities.

## Usage
Usually invoked via the main `tusk` binary:
```bash
./tusk make:controller User
```
