# Tusk Composer Application Skeleton Design

## Goal

Give Tusk one idiomatic, Composer-native path for creating an application while
keeping the Framework package, application template, and Engine configuration
responsibilities unambiguous.

## Product decision

Keep `tusk/framework` as a reusable Composer library. Publish the official
application template as the separate public repository `tusk-framework/tusk-app`
and Composer project package `tusk/app`. A new application starts with:

```sh
composer create-project tusk/app catalog
```

Composer owns project creation and dependency installation. The Tusk Engine's
`tusk init` remains an operation for adding Engine configuration to an existing
project; it does not create an application skeleton. The Framework CLI will no
longer expose a competing `init <name>` project generator.

## Ownership

- **Composer / `tusk/app`:** creates the application directory, installs the
  declared Framework release, and owns the user-editable application skeleton.
- **Tusk Framework / `tusk/framework`:** provides PHP libraries, application
  bootstrap contracts, Framework commands, and code generation within an
  existing application. It does not own a second project-template copy.
- **Tusk Engine:** validates and manages project/runtime configuration,
  provisions the selected toolchain, dispatches Framework commands, and
  supervises RoadRunner. It does not create application files.
- **RoadRunner:** owns HTTP/job worker transport, pools, IPC, and process
  supervision.

## Application skeleton contract

The initial `tusk/app` project contains the currently supported application
entrypoint and configuration, not legacy `worker.php`/`config.php` bootstrap
conventions. Its tracked files include:

- `composer.json` with `type: project` and a stable constraint on `tusk/framework`;
- `bootstrap/app.php`, `bootstrap/providers.php`, `public/index.php`, routes,
  and conventional application source directories;
- `tusk.json`, `.rr.yaml`, and ignore rules compatible with Engine-managed
  `.tusk/` runtime state;
- environment examples without credentials or generated production secrets;
- a small typed HTTP example aligned with the supported DTO validation and
  RFC 9457 behavior (no database dependency in the default example);
- concise project instructions using commands that exist in the published
  Engine and Framework versions.

The skeleton does not embed Framework source, Composer's `vendor/`, generated
Engine runtime artifacts, or RoadRunner worker supervision code. Its
`composer.json` is the root application manifest; it must not masquerade as the
`tusk/framework` library package.

## Framework CLI transition

Remove the Framework-owned `init` command from the explicit command catalog and
remove `ProjectGenerator` plus its project-template stubs from the Framework
repository once the new skeleton is the supported source of truth. Keep
`make:controller`, `make:entity`, migration commands, `build`, and other
Framework application commands. The Engine's `tusk init` remains available for
existing projects and continues to create only `tusk.json`.

Update Framework README, CLI README, typed HTTP guide, generated database
guidance, and offline Engine documentation as needed so all user-facing setup
guides begin with `composer create-project tusk/app <directory>` and then use
the Engine's `tusk` command path. Existing-project initialization must be
documented separately from new-project creation.

## Verification and release integration

The skeleton repository owns tests for its manifest, required files, package
constraints, and clean `composer install`. The coordinated integration smoke
must create an application from the skeleton, install dependencies, validate
Engine diagnostics, build the Framework application, start it through
RoadRunner, call the typed HTTP example, and verify graceful shutdown. It must
use the same published package/Engine command path documented to users and must
not silently fall back to an old PHP worker entrypoint.

Before the golden-path issue is closed, a release-level check must prove the
actual published `tusk/app` package resolves a compatible `tusk/framework`
release and the selected Engine supports the generated configuration. If the
repositories are not released in lockstep, the compatibility constraints and
smoke-test inputs must be pinned explicitly rather than using floating
development branches.

## Error behavior

- Composer remains responsible for reporting package/dependency installation
  failures; the skeleton must not hide or swallow Composer's exit status.
- The first Engine diagnostic after creation must identify missing Engine or
  runtime prerequisites and point to the relevant setup command.
- A generated app with absent or invalid configuration must fail before
  starting RoadRunner, with an actionable diagnostic.
- No setup step may overwrite files in an existing directory; project creation
  is delegated to Composer's destination-directory safeguards.

## Out of scope

- Replacing Composer or adding another project package manager.
- Teaching the Engine to download or render PHP application templates.
- Framework/runtime duplication of RoadRunner pools, IPC, or supervision.
- Laravel/Spiral parity, Swoole support, and speculative service-mesh features.
- A second API/microservice skeleton variant before the default application
  path is proven.

## Acceptance criteria

1. `composer create-project tusk/app catalog` creates a usable application
   whose manifest requires `tusk/framework` and whose entrypoint is
   `bootstrap/app.php`.
2. The Framework no longer offers a conflicting project-creation `init`
   command or maintains duplicate project-template files.
3. Engine `tusk init` is documented and tested as configuration-only for an
   existing project; it is not needed for a newly created skeleton that already
   contains `tusk.json`.
4. Framework, Engine, and skeleton documentation show the same command sequence
   and do not reference commands absent from the corresponding release.
5. The coordinated clean-project smoke test reaches the typed HTTP example
   through Engine → RoadRunner → Framework, preserves command/process failures,
   and shuts down gracefully on supported CI platforms.
6. Package and Engine compatibility are validated against release artifacts,
   not only local source checkouts.
