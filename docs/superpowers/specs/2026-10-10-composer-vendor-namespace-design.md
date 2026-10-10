# Composer Vendor Namespace Migration Design

## Goal

Publish the Tusk Framework under the Packagist vendor namespace controlled by
the project, avoiding the already-claimed `tusk` vendor namespace while keeping
GitHub repository identity and internal component package names intact.

## Approved package identity

- Root Composer package: `tusk-framework/framework`.
- GitHub source repository: `tusk-framework/tusk-framework`.
- Skeleton project package: remains `tusk/app`.
- Internal component packages such as `tusk/core`, `tusk/web`, and `tusk/cli`
  remain unchanged; they are replaced by the root framework package as today.

The Composer name and GitHub repository slug are separate identifiers. Release
workflows must continue signing/uploading releases against the GitHub repository
slug while Composer metadata, install commands, dependency constraints, and
Packagist documentation use `tusk-framework/framework`.

## Scope

Coordinate the migration across these repositories:

1. `tusk-framework/tusk-framework`: root package metadata, CLI-generated
   projects, release runbook, release fixtures, and historical release design
   artifacts that still describe the pending package-registration workflow.
2. `tusk-framework/tusk-app`: the open Composer skeleton PR must require the new
   package name and assert the corresponding manifest key.
3. `tusk-framework/tusk-engine`: local skeleton smoke-test Composer path
   repository version mapping must identify the root package under its new name.
4. `tusk-framework/tuskpress`: dependency declaration and installed vendor path
   must follow the new root package name.

Do not rename the GitHub repositories, the skeleton project package, internal
Tusk component package names, namespaces, or PHP namespaces. Do not publish a
release or submit to Packagist as part of this code migration.

## Availability and release gate

Before the first public registration or release, verify that Packagist accepts
`tusk-framework/framework`. If it is unavailable, stop and choose another
project-controlled vendor name before publishing; Composer package names are
public identifiers and must not be changed after publication. Once the package
is registered, verify that Packagist exposes the intended GitHub source and
that a clean skeleton install resolves the stable release.

## Compatibility and rollout

The existing `tusk/framework` identifier cannot be used for this package
because its vendor namespace is controlled by another Packagist account. Since
this package has not yet been released or adopted, migrate consumers directly
instead of introducing an alias, transition package, or compatibility layer.
All in-scope consumers must be updated before the first release is published.

## Validation

- Framework Composer manifest validates with root name
  `tusk-framework/framework`.
- Release tests prove Packagist-facing package identity is the Composer name
  while GitHub release operations target `tusk-framework/tusk-framework`.
- Framework test suite and release tests pass.
- Skeleton tests require `tusk-framework/framework`; skeleton CI passes against
  the stable version once it is available.
- Engine skeleton smoke test installs the local framework path repository using
  the renamed package key and passes.
- TuskPress Composer manifest and bootstrap resolve the framework from
  `vendor/tusk-framework/framework`; its validation/smoke tests pass.
- Repository-wide search finds no stale root-package references to
  `tusk/framework`; intentional internal `tusk/*` packages and migration-history
  context are documented or retained only where semantically correct.
