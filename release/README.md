# Framework release operations

This repository publishes the Composer package `tusk-framework/framework` from GitHub
releases. PHP and Composer remain the framework runtime; Node.js 24 is used only
by CI to analyze Conventional Commits and run release-tool tests.

## Packagist integration

After the release-automation PR is merged:

1. Register `https://github.com/tusk-framework/tusk-framework` as the
   `tusk-framework/framework` package at [Packagist](https://packagist.org/packages/submit).
2. Enable Packagist's GitHub integration/webhook for the repository and confirm
   Packagist can read `composer.json` and receive tag updates.
3. Keep any Packagist token in Packagist/GitHub settings only. The GitHub release
   workflow does not need a Packagist secret; synchronization is webhook-driven.

The package is registered and Packagist currently indexes `v1.0.0`. The
one-time registration and webhook setup were completed for the first
publication. Ordinary releases do not require a Packagist secret or a manual
version selection.

## Release history

The guarded bootstrap published `v0.3.2` as the first Packagist package. The
Framework's first stable API line began with `v1.0.0`, published automatically
from `main` after the breaking removal of the unused declarative HTTP client.
Both releases were produced by GitHub Actions; neither tag should be recreated
or moved manually. The old one-time `0.3.2` bootstrap is no longer applicable.

## Stable API and compatibility policy

`v1.0.0` starts the Framework's stable API line. Stability is a compatibility
commitment, not a claim that the Framework has feature parity with other
frameworks or that every optional capability is complete.

The public contract includes APIs documented in the Framework documentation
and README, application bootstrap and lifecycle contracts, PHP interfaces,
attributes and extension points explicitly presented for application use,
documented CLI commands and options, configuration keys and semantics, and the
generated application skeleton contract. Internal implementation details that
are not documented or exposed as extension points are not compatibility
guarantees.

- Backward-compatible bug fixes and security fixes are patch releases.
- Backward-compatible public functionality is released in a minor version.
- Incompatible changes to the public contract require a major version.
- Before removing or changing a public contract, mark it deprecated in a minor
  release, document its replacement and migration, and remove it only in a
  subsequent major release. Security fixes may require an exception, which
  must be called out in the release notes.
- The CI release workflow selects the next patch, minor, or major version from
  Conventional Commits. A `fix:` selects patch, `feat:` selects minor, and a
  breaking marker (`!` or `BREAKING CHANGE:`) selects major. Documentation-only
  and other non-release commits do not publish a version.

The supported PHP matrix and release checks are defined by the CI workflow.
Every release must pass validation and all supported PHP jobs before CI creates
the tag, archive, checksum, provenance attestations, and GitHub Release.

## Automatic version selection

Successful pushes to `main` publish when Conventional Commits contain a
release-worthy change. The workflow calculates the next version from the
latest stable release (currently `v1.0.0`) using these rules:

| Commit | Version change |
| --- | --- |
| `fix:` | patch |
| `feat:` | minor |
| `feat!:` or a `BREAKING CHANGE:` footer | major |
| `docs:`, `test:`, `chore:`, `perf:`, `revert:`, and other types | no release |

The release job waits for `validate` and every supported PHP test job. Pull
requests have read-only permissions. GitHub Actions creates the tag, archive,
checksum, and provenance; do not create or move release tags manually.

## Verify release provenance

Install a current GitHub CLI with attestation support, then verify each asset
from the GitHub Release page or a local download. Replace `<version>` and
`<source-commit>` with the published values:

```sh
gh attestation verify tusk-framework-<version>.tar.gz \
  --repo tusk-framework/tusk-framework \
  --signer-workflow tusk-framework/tusk-framework/.github/workflows/ci.yml \
  --source-ref refs/heads/main \
  --source-digest <source-commit> \
  --deny-self-hosted-runners

gh attestation verify tusk-framework-<version>.tar.gz.sha256 \
  --repo tusk-framework/tusk-framework \
  --signer-workflow tusk-framework/tusk-framework/.github/workflows/ci.yml \
  --source-ref refs/heads/main \
  --source-digest <source-commit> \
  --deny-self-hosted-runners
```

The checksum file contains the SHA-256 digest of the archive. Verify it with
`sha256sum -c tusk-framework-<version>.tar.gz.sha256` on Linux/macOS, or
`Get-FileHash tusk-framework-<version>.tar.gz -Algorithm SHA256` on PowerShell.

## Interrupted publication and recovery

For current releases, rerun the failed CI release job against the same source
commit; recovery verifies the existing tag, archive, checksum and provenance
before completing only missing release objects. Do not manually create, move,
or delete a published tag to retry a release.

The automatic path requires the historical `v0.3.2` bootstrap tag to exist. If
that tag is absent, a normal `main` push plans no release; do not assume it will
bootstrap automatically. The remaining `workflow_dispatch` input accepts only
`0.3.2` and exists solely for the guarded first-publication bootstrap, not for
choosing current versions. If the bootstrap tag is unexpectedly absent, inspect
the complete tag/release history and resolve it deliberately before retrying.

When an interrupted publication is found, recovery rebuilds the archive from
the tagged commit, verifies its existing provenance, checks GitHub's asset
digests, and creates/uploads only missing release objects. A tag/SHA mismatch,
duplicate release, or conflicting asset digest fails closed and requires
maintainer investigation before retrying.

Packagist synchronization is independent of GitHub publication. If a valid
GitHub release exists but Packagist has not indexed its tag, inspect the
Packagist webhook/integration and request a package update there; do not create
another GitHub tag or release to retry registry synchronization.
