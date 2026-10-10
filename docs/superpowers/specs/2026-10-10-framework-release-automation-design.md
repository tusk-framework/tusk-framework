# Framework Release Automation Design

## Goal

Make `tusk/framework` discoverable and installable through Composer, publish the
initial `0.3.2` release required by `tusk/app`, and automate subsequent stable
releases from successful `main` builds using Conventional Commits.

## Current state

- The Framework has no Git tags or GitHub Releases.
- `composer.json` names the package `tusk/framework` but does not declare a
  package version, which is appropriate for a tag-versioned library.
- `tusk/app` requires `tusk/framework:^0.3.2`.
- CI and the PHP test matrix are separate workflows. Both currently pass on
  `main`, but no release workflow exists.
- The contributing guide describes Conventional Commit release rules and
  attested release outputs that are not implemented yet.
- The package is not currently resolvable from Packagist.

## Approved decisions

1. Consolidate the package validation, runtime guard, and supported-PHP test
   matrix into one CI workflow. A release job must depend on every required
   validation and test job in that workflow, so a release cannot race another
   workflow or run after only a subset of checks.
2. Bootstrap the first release explicitly as `v0.3.2`, matching the current
   application skeleton requirement. Do not infer the first version from the
   entire untagged history.
3. For subsequent pushes to `main`, determine the next stable SemVer version
   from Conventional Commits: `fix` is patch, `feat` is minor, and `!` or a
   `BREAKING CHANGE` footer is major. Documentation-, test-, and maintenance-
   only commits do not release by themselves.
4. Only the successful `main` workflow may write tags or releases. Pull request
   runs remain read-only. The initial release is a controlled workflow
   dispatch against `main` that reruns the full checks and accepts only the
   approved bootstrap version while no release tag exists.
5. Publish an immutable Git tag and GitHub Release, create a source archive
   from that exact tag, attach its checksum and GitHub Actions provenance
   attestation, and never store signing private keys in the repository.
6. Register `tusk/framework` on Packagist once and configure its GitHub
   integration/webhook to ingest new tags. Packagist registration and its
   initial synchronization are release prerequisites; credentials remain in
   Packagist/GitHub settings, not source control.

## Release flow

### Initial publication

1. Merge the reviewed release-automation change through the normal PR process.
2. Confirm the unified CI workflow is green on the resulting `main` commit.
3. Register `tusk/framework` on Packagist and verify Packagist can read the
   repository metadata and tag updates.
4. Dispatch the initial-release mode against `main`. It reruns all required
   checks and refuses to proceed unless the version is exactly `0.3.2`, the
   repository has no prior release tag, and the commit is on `main`.
5. Create `v0.3.2`, the GitHub Release, the source archive, checksum, and
   provenance attestation from the same commit. Verify Composer resolves
   `tusk/framework:^0.3.2` before retrying the `tusk/app` CI.

### Subsequent publications

1. A push to `main` starts validation and the supported PHP test matrix.
2. The release job waits for every required job. Any failure or cancellation
   prevents release creation.
3. The release calculator inspects Conventional Commits since the last stable
   tag. If there is no release-worthy commit, it succeeds without publishing.
4. Otherwise it creates the next immutable tag and GitHub Release, then
   attaches a tag-derived source archive, checksum, and provenance attestation.
5. The Packagist GitHub integration ingests the new tag. A registry outage
   does not move or delete the immutable GitHub tag; synchronization can be
   retried independently.

## Safety and permissions

- Use least-privilege permissions: read-only for validation/test jobs; release
  contents write and OIDC/attestation permissions only on the release job.
- Do not expose release credentials to pull-request workflows.
- Serialize release jobs and fail closed if a target tag already exists or
  points at another commit. Never force-move or delete a published version.
- A failed release before tag creation may be retried. After a tag is created,
  corrections require a new version rather than rewriting history.
- Keep the workflow and contributor documentation consistent about actual
  release triggers, bootstrap steps, and provenance verification.

## Verification requirements

- Run the unified CI suite across the currently supported PHP versions and
  retain the RoadRunner-only runtime guard.
- Test release-version decisions for patch, minor, major, non-release commits,
  and the explicit `0.3.2` bootstrap; test that a second bootstrap or an
  existing/mismatched tag is rejected.
- Verify pull-request events cannot create tags, GitHub Releases, or
  attestations.
- Verify the release job is skipped when any required CI job fails and runs
  only for successful pushes to `main` or the validated initial dispatch.
- Verify the release archive corresponds to the tagged commit and that its
  checksum and GitHub provenance can be validated.
- After Packagist registration, verify `composer show tusk/framework --all`
  exposes `0.3.2`, then install the dependency from a clean `tusk/app` checkout
  and run its tests and RoadRunner smoke test.

## Out of scope

- Publishing development, alpha, beta, or release-candidate versions.
- Releasing independent versions for the Framework's internal packages; they
  remain replaced by the root `tusk/framework` package.
- Publishing Engine binaries or changing the Engine release process.
- Storing or distributing long-lived private signing keys.
