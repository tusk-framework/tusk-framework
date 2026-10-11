# Remove Mock Declarative HTTP Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the unfinished declarative HTTP client and its orphaned Consul discovery classes, preserve the supported PSR-18 resilience API, and coordinate the breaking release and App consumer update.

**Architecture:** Delete only the six public proof-of-concept classes identified by the approved spec. Keep `Psr18ResilientClient` and the rest of the resilience/cloud integrations unchanged; use the existing release automation for the major version, then update the separate App repository only after the stable package is published.

**Tech Stack:** PHP 8.2–8.4, Composer, PHPUnit 10, GitHub Actions, semantic-release.

**Spec:** `docs/superpowers/specs/2026-10-10-remove-mock-http-client-design.md`

## Global Constraints

- Delete `tusk-cloud/src/Http/ApiClient.php`, `HttpClientFactory.php`, `Methods.php`, and `tusk-cloud/src/Discovery/DiscoveryClientInterface.php`, `ServiceInstance.php`, `ConsulDiscoveryClient.php`.
- Keep `Tusk\Cloud\Resilience\Http\Psr18ResilientClient`, its behavior, dependencies, and tests unchanged.
- Treat the deletion as a breaking change and allow release automation to select the major version; do not create or move tags manually.
- Do not update `tusk-app` to `^1.0` until the Framework's stable `v1.0.0` is published and available from Packagist.
- Sync each repository's local `main` from `origin/main` before creating a branch; use a pull request for every repository change.

## Review Focus

- A deleted class remains referenced by active PHP source or tests — repository-wide search must return no such references.
- The PSR-18 resilience API is accidentally changed during the deletion — its existing focused test file must remain untouched and pass.
- Mock/debug stdout behavior survives in another source file — search for the distinctive `[ApiClient]` output and verify it is absent from active code.
- The release is misclassified as patch/minor — implementation PR title/body must carry a breaking-change marker and the published candidate must be `v1.0.0`.
- The App constraint is advanced before the Framework package is consumable — wait for GitHub Release and Packagist availability before opening the App consumer PR.

---

### Task 1: Remove the unfinished client and orphaned discovery implementation

**Files:**
- Delete: `tusk-cloud/src/Http/ApiClient.php`
- Delete: `tusk-cloud/src/Http/HttpClientFactory.php`
- Delete: `tusk-cloud/src/Http/Methods.php`
- Delete: `tusk-cloud/src/Discovery/DiscoveryClientInterface.php`
- Delete: `tusk-cloud/src/Discovery/ServiceInstance.php`
- Delete: `tusk-cloud/src/Discovery/ConsulDiscoveryClient.php`
- Preserve: `tusk-cloud/src/Resilience/Http/Psr18ResilientClient.php`
- Preserve: `tusk-cloud/tests/Resilience/Http/Psr18ResilientClientTest.php`

**Interfaces:**
- Consumes: the approved deletion scope in the spec.
- Produces: no replacement API; the existing PSR-18 resilient decorator remains the supported low-level outbound HTTP integration.

- [ ] **Step 1: Inventory references** with `rg -n "ApiClient|HttpClientFactory|DiscoveryClientInterface|ServiceInstance|ConsulDiscoveryClient|\[ApiClient\]" --glob '!vendor/**' .`. Confirm references outside the six target files are limited to the approved spec/plan and identify any active source, tests, examples, or user documentation that must be updated.
- [ ] **Step 2: Delete the six target files** and remove only any active references discovered in Step 1. Do not edit the PSR-18 resilient client or its focused test.
- [ ] **Step 3: Verify the deleted API is unavailable** by regenerating Composer autoload metadata and checking `class_exists`/`interface_exists` for all six removed symbols; each must return `false`.
- [ ] **Step 4: Verify no active references or mock output remain** with the inventory search, excluding the historical spec and implementation plan from the active-code assertion. Expected: no hits in PHP source, tests, examples, or user-facing docs.
- [ ] **Step 5: Run the focused retained API suite** with `vendor/bin/phpunit tusk-cloud/tests/Resilience/Http/Psr18ResilientClientTest.php`. Expected: all tests pass without modifying the test file.
- [ ] **Step 6: Run the complete suite** with `vendor/bin/phpunit --testdox` and validate Composer metadata with `composer validate --strict`. Expected in CI: all tests pass on PHP 8.2, 8.3, and 8.4. The current local PHP 8.5.10 baseline has 4 failures, 7 errors, and 4 skips on `main`; compare against that baseline and do not attribute pre-existing failures to this change.
- [ ] **Step 7: Open the Framework PR** with title `refactor!: remove mock declarative HTTP client` and a `BREAKING CHANGE:` explanation naming the six removed public classes. Keep it as a PR, wait for required CI, and merge only when checks pass.

### Task 2: Verify the major release and migrate the App consumer

**Files:**
- Verify: Framework GitHub Actions release workflow and `release/README.md`.
- Modify in a separate repository/PR after publication: `tusk-app/composer.json` (and `composer.lock` only if tracked and changed by the supported Composer update workflow).

**Interfaces:**
- Consumes: the merged Framework deletion PR and automated release from `main`.
- Produces: the App skeleton requiring the actually published `tusk-framework/framework:^1.0` package.

- [ ] **Step 1: Verify release automation's candidate** after the Framework PR merges. Confirm the successful `main` workflow publishes `v1.0.0`, with the release assets/provenance expected by `release/README.md`; do not manually dispatch a version or create a tag.
- [ ] **Step 2: Verify Packagist availability** with `composer show tusk-framework/framework --all` or the Packagist package page. Do not proceed while `1.0.0` is unavailable.
- [ ] **Step 3: Synchronize `tusk-app` `main`**, create a `chore/framework-v1-constraint` branch, and update only its Framework constraint from `^0.3.2` to `^1.0`. Refresh a tracked lock file only through the repository's normal Composer workflow.
- [ ] **Step 4: Run App validation** from a clean checkout: Composer validation/install and the App's documented test/smoke commands. Expected: dependency resolution uses the published Packagist package, with no path repository or local checkout required.
- [ ] **Step 5: Open and merge the App PR** after its CI passes. Link it and the Framework release from Framework issue #6; close #6 only after the Framework PR, `v1.0.0`, Packagist availability, and App constraint migration are all verified.

## Self-review

- Spec coverage: all six deletions, active-reference audit, unchanged PSR-18 integration, full supported PHP matrix, breaking release, App constraint sequencing, and issue closure evidence are covered above.
- Scope: implementation is one Framework removal PR followed by an independently reviewable App consumer PR; no new HTTP transport or discovery subsystem is introduced.
- Release behavior: the configured conventional-commit rules map breaking changes to a major release, so the PR title/body explicitly carry that signal and the plan verifies the actual `v1.0.0` publication rather than assuming it.
- Local baseline: PHP 8.5.10 on the current Framework `main` produced 617 tests, 2,850 assertions, 4 failures, 7 errors, and 4 skipped. The supported CI matrix remains PHP 8.2–8.4; compare local outcomes to this baseline and use CI as the release gate.
