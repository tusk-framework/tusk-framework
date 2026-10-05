# Contributing to Tusk Framework

Thank you for helping improve Tusk Framework. Tusk is a modular PHP ecosystem
for long-lived applications, with domain-focused components, compiled
configuration, and RoadRunner-based persistent workers.

Please read the [Code of Conduct](CODE_OF_CONDUCT.md) before participating.

## Ways to contribute

You can help by:

- reporting reproducible bugs;
- proposing focused improvements through issues;
- improving documentation and examples;
- adding tests or improving diagnostics;
- implementing features that fit an existing package boundary; or
- reviewing pull requests and release changes.

For security vulnerabilities, exposed credentials, or trust-chain concerns,
use a private GitHub security report and do not open a public issue.

## Before you start

1. Search existing issues and pull requests before opening a new one.
2. For a substantial change, open an issue first and describe the problem,
   proposed behavior, compatibility impact, and acceptance criteria.
3. Keep changes focused. Separate unrelated refactors, formatting changes,
   and feature work into different pull requests.
4. Never commit private keys, tokens, credentials, generated release assets,
   vendor directories, or local caches.

## Development environment

The Framework currently supports PHP 8.2 and newer. PHP 8.2, 8.3, and 8.4
are exercised in CI. Composer is the dependency resolver and lockfile
authority.

From the repository root, install dependencies and run the normal checks:

```bash
composer validate --strict
composer install --prefer-dist --no-progress
vendor/bin/phpunit --testdox
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

If a change affects generated applications or the RoadRunner runtime, also run
the relevant skeleton or runtime checks documented in the affected package and
in the Engine repository. Do not weaken a test merely because a local runtime
dependency is unavailable; explain the limitation in the pull request.

## Package boundaries

Keep responsibilities explicit:

- `tusk-core` owns application lifecycle and dependency injection;
- `tusk-web` owns routing, middleware, and HTTP contracts;
- `tusk-data` owns data access abstractions;
- `tusk-runtime` owns the PHP-side persistent worker contract;
- `tusk-contracts` owns shared interfaces and stable value contracts;
- `tusk-security` owns authentication and authorization primitives;
- `tusk-validation` owns validation contracts and rules;
- `tusk-events` owns event dispatching contracts;
- `tusk-config` owns configuration loading and access; and
- `tusk-cli` owns scaffolding, compilation, and developer tooling.

RoadRunner owns worker pools, HTTP serving, Goridge IPC, recycling, and
process-level supervision. The Tusk Engine owns the Go control plane above
RoadRunner. Framework changes should use the established runtime contracts
instead of duplicating those responsibilities.

When adding a package or changing a public contract, document the dependency
direction and compatibility impact. Preserve the root Composer package's
`replace` declarations unless the package topology is intentionally changing.

## Branches and commits

Use a descriptive branch name without a `codex/` prefix. Examples:

```text
feat/compiled-config-cache
fix/request-scope-reset
docs/contributing-guide
```

Use [Conventional Commits] for commit messages. The type controls automated
release versioning:

- `fix:` produces a patch release;
- `feat:` produces a minor release;
- `feat!:` or a `BREAKING CHANGE:` footer produces a major release; and
- `docs:`, `test:`, `chore:`, and similar non-release commits do not create a
  release by themselves.

Use an imperative, specific subject, for example:

```text
feat: add typed configuration records
```

## Pull requests

A pull request should explain:

- what changed and why;
- the user-visible or operational impact;
- the packages, APIs, configuration, or generated output affected;
- how the change was verified; and
- risks, compatibility concerns, migrations, and deliberate follow-ups.

Before requesting review:

- update the branch from the current `main` as appropriate;
- run the relevant Composer, PHPUnit, PHPStan, and Pint checks;
- add or update tests for changed behavior;
- update documentation and examples when configuration or behavior changes;
- review the diff for secrets, unrelated changes, and accidental generated
  files; and
- confirm that the PR title and commits follow Conventional Commits.

Keep review discussions technical, specific, and respectful. A review comment
should identify the behavior or risk, explain why it matters, and suggest a
clear path forward when possible.

## Tests and implementation expectations

New behavior should have a focused regression test. Prefer tests that exercise
real framework contracts over tests that only assert mock interactions.
Changes involving request scope, worker lifecycle, retries, resilience,
security, configuration boundaries, or generated code should include failure-
path coverage.

Use typed properties, readonly value objects, explicit interfaces, and small
services where they improve clarity. Avoid adding framework magic when a clear
PHP API is easier to understand and test.

## Release and package changes

Release automation is driven by Conventional Commits and publishes only after
the required main-branch checks pass. Do not create or move release tags by
hand unless a maintainer has asked you to perform a release operation.

Package and runtime changes must include:

- updated tests for the affected public contract;
- updated package README or root documentation when behavior changes;
- a compatibility note for supported PHP versions and RoadRunner; and
- migration guidance for breaking changes.

Release artifacts and provenance must never contain private signing material.
The release workflow is responsible for producing signed or attested release
outputs from the reviewed source tree.

## Questions

If you are unsure whether a change belongs in the Framework, open an issue with
a short design proposal before implementing it. Maintainers can help identify
the correct boundary between Framework packages, Tusk Engine, RoadRunner, and
the PHP application.

[Conventional Commits]: https://www.conventionalcommits.org/en/v1.0.0/
