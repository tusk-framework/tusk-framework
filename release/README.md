# Framework release operations

This repository publishes the Composer package `tusk-framework/framework` from GitHub
releases. PHP and Composer remain the framework runtime; Node.js 24 is used only
by CI to analyze Conventional Commits and run release-tool tests.

## One-time Packagist setup

After the release-automation PR is merged:

1. Register `https://github.com/tusk-framework/tusk-framework` as the
   `tusk-framework/framework` package at [Packagist](https://packagist.org/packages/submit).
2. Enable Packagist's GitHub integration/webhook for the repository and confirm
   Packagist can read `composer.json` and receive tag updates.
3. Keep any Packagist token in Packagist/GitHub settings only. The GitHub release
   workflow does not need a Packagist secret; synchronization is webhook-driven.

Do not start the initial release until Packagist recognizes the package and
the default branch is protected with required checks `validate`, `test (8.2)`,
`test (8.3)`, and `test (8.4)`.

## First release: `v0.3.2`

The first `main` push after installation of this workflow does not infer a
version from untagged history. Once Packagist is ready, open GitHub Actions →
CI → **Run workflow**, select branch `main`, and select version `0.3.2`.
Dispatch reruns validation and the PHP matrix. It refuses a stale/non-main SHA,
an existing stable tag, or any version other than `0.3.2`.

On success, CI creates immutable tag `v0.3.2`, a GitHub Release, a deterministic
source archive and SHA-256 checksum, and GitHub Actions provenance attestations
for both files. Verify the package from a clean application checkout:

```sh
composer show tusk-framework/framework --all
composer require 'tusk-framework/framework:^0.3.2'
```

The application repository's dependency workflow should then pass using the
Packagist package rather than a local path repository.

## Later versions

Only successful pushes to `main` can publish. Release calculation starts after
`v0.3.2` exists and follows these rules:

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

If the initial dispatch stops before creating `v0.3.2`, fix the cause and run
the guarded `0.3.2` dispatch again. If it stops after creating the tag, rerun
the dispatch against the same `main` SHA; the preflight recovers only the
existing `v0.3.2` tag when it points to that exact SHA. A different SHA or
another pre-existing stable tag is not a bootstrap retry and fails closed.

For later releases, the next successful `main` workflow inspects the latest
stable tag/release before calculating another version. A missing stable tag
means the preflight is a no-op and normal bootstrap/version selection proceeds;
it does not create a recovery manifest. When an interrupted publication is
found, recovery rebuilds the archive from the tagged commit, verifies its
existing provenance, checks GitHub's asset digests, and creates/uploads only
missing release objects. A tag/SHA mismatch, duplicate release, or conflicting
asset digest fails closed and requires maintainer investigation before retrying.

Packagist synchronization is independent of GitHub publication. If a valid
GitHub release exists but Packagist has not indexed its tag, inspect the
Packagist webhook/integration and request a package update there; do not create
another GitHub tag or release to retry registry synchronization.
