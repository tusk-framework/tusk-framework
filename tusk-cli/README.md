# Tusk CLI

The **Tusk CLI** provides developer tooling and command-line interfaces for the Tusk Framework. 
It works as the primary build tool to compile the Ahead-Of-Time (AOT) files.

## Responsibilities
- **Generator**: Generate controllers, models, and migrations.
- **Maintenance**: Clear caches, run migrations.
- **Tooling**: Diagnosis and setup utilities.

## Generated applications

After installing the generated project's Composer dependencies, use the Tusk
Engine CLI to run Framework commands. The Engine forwards non-built-in
commands to the Composer-installed `vendor/bin/tusk` entrypoint:

```bash
tusk list
tusk make:controller User
```

Engine commands and scripts configured by the project keep their own priority.
If the Framework command is unavailable, run `tusk install` to install the
project dependencies.

## Framework development checkout

When working in the Framework repository itself, invoke its development CLI
from the repository root:

```bash
./tusk make:controller User
```
# Generated job applications

The generated skeleton includes `app/Jobs/WelcomeJob.php`, a JSON producer example, and `withJobs(__DIR__.'/../app/Jobs')`. Configure RoadRunner with `RR_MODE=jobs` to consume jobs; HTTP remains the default. Delivery is at least once, so handlers should be idempotent. Retry limits live under `runtime.jobs.retry`; driver-specific failed-message retention is controlled by RoadRunner.
