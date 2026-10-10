# Framework Release Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish `tusk/framework` as a Composer package at `v0.3.2`, then automatically create later stable releases from successful `main` builds.

**Architecture:** Keep PHP/Composer as the framework runtime and add a locked, development-only Node release toolchain using semantic-release. Consolidate validation and the existing PHP matrix into one Actions workflow; a least-privilege release job depends on every required job, creates and attests release assets, and supports a guarded one-time `0.3.2` bootstrap. Packagist registration and its GitHub update hook are documented one-time operator steps.

**Tech Stack:** GitHub Actions, PHP 8.2–8.4, Composer, PHPUnit, Node.js 24, semantic-release, `actions/attest`, GitHub CLI, Packagist.

**Spec:** `docs/superpowers/specs/2026-10-10-framework-release-automation-design.md`

## Global Constraints

- The first published version is exactly `v0.3.2`; do not derive it from the untagged commit history.
- Automatic version calculation remains disabled until `v0.3.2` exists; never analyze the untagged project history as a release range.
- Later stable versions follow Conventional Commits: `fix` → patch, `feat` → minor, breaking change → major; non-release commit types do not publish.
- Release writes are allowed only on successful pushes to `main` or the explicitly guarded initial-release dispatch; pull requests remain read-only.
- Keep the required branch-protection status contexts exactly `validate`, `test (8.2)`, `test (8.3)`, and `test (8.4)`.
- The Framework's runtime dependencies remain Composer/PHP only; Node is a locked CI/development release tool.
- Pin GitHub Actions used by the release path to reviewed full commit SHAs; include the upstream version in comments for maintenance.
- Never force-move a published tag or place long-lived signing material in the repository.
- Preserve the RoadRunner-only runtime guard and existing `main`/`develop` test coverage.

## Review Focus

- A pull-request event must never create a tag, release, or attestation; assert the workflow event/branch guards and job permissions.
- Any failed or cancelled required matrix job must prevent the release job from running; assert explicit `needs` and success conditions.
- Commits such as `docs:` and `chore:` must result in no release; test semantic-release commit analysis.
- Bootstrap dispatches with a wrong version, non-main ref, stale SHA, or existing tag must fail without overwriting release state; test each rejected input.
- A retry after partial publication must not move a tag or duplicate release assets; test same-SHA recovery and conflicting-SHA rejection.

---

## File Map

- Create root `package.json` and `package-lock.json` for pinned, development-only semantic-release dependencies and Node tests; this does not add Node to the Composer runtime.
- Create root `.releaserc.json` to configure only Conventional Commit analysis, release notes, GitHub Releases, `main`, and `v${version}` tags; do not run the npm publish plugin.
- Create `release/scripts/bootstrap-policy.mjs` and `release/test/bootstrap-policy.test.mjs` for the one-time `0.3.2` guard.
- Create `release/scripts/plan-release.mjs` and `release/test/commit-analysis.test.mjs` to expose and test the next-release decision before publication.
- Create `release/scripts/create-source-archive.mjs` and `release/test/source-archive.test.mjs` for a deterministic tag-source archive and SHA-256 checksum.
- Create `release/scripts/recovery-policy.mjs` and `release/test/recovery-policy.test.mjs` to safely resume incomplete same-tag publication.
- Modify `.github/workflows/ci.yml` to contain validation, the supported PHP test matrix, and the gated release job.
- Remove `.github/workflows/tests.yml` after its test matrix and supported branch triggers move into `ci.yml`.
- Modify `.gitignore` for `/node_modules/` and generated `/release/dist/` assets.
- Modify `CONTRIBUTING.md` and `README.md`; create `release/README.md` for the initial Packagist setup, bootstrap, recovery, and attestation verification runbook.

## Tasks

### Task 1: Pin release tooling and prove version decisions

**Files:**
- Create: `package.json`
- Create: `package-lock.json`
- Create: `.releaserc.json`
- Create: `release/test/commit-analysis.test.mjs`
- Create: `release/scripts/plan-release.mjs`

**Interfaces:**
- `planNextRelease({ repositoryRoot, env })` returns `null` when there is no release-worthy commit, or `{ version, type, tag }` when semantic-release determines a release.
- The release configuration uses `main`, tag format `v${version}`, and GitHub Releases; it does not publish to npm or modify Composer package metadata.

- [ ] **Step 1: Write the commit-analysis tests.** Cover `fix:`, `feat:`, `feat!:` and a `BREAKING CHANGE:` footer, plus non-release `docs:`/`chore:` commits. Assert the exact release type or `null`.
- [ ] **Step 2: Run the focused tests and confirm the expected failure** because the release tool and `planNextRelease` do not exist yet.
- [ ] **Step 3: Add the pinned Node 24 toolchain and semantic-release configuration.** Lock exact dependency versions in the root npm manifest and define `npm test`; configure the analyzer, release-notes generator, and GitHub plugin only.
- [ ] **Step 4: Implement `planNextRelease({ repositoryRoot, env })`.** Use semantic-release's dry-run/API result; do not duplicate Conventional Commit parsing in custom code.
- [ ] **Step 5: Run `npm ci` and `npm test`;** confirm all decision cases pass and Composer metadata/runtime dependency files are unchanged.

### Task 2: Validate bootstrap policy and build attested source assets

**Files:**
- Create: `release/scripts/bootstrap-policy.mjs`
- Create: `release/test/bootstrap-policy.test.mjs`
- Create: `release/scripts/create-source-archive.mjs`
- Create: `release/test/source-archive.test.mjs`
- Modify: `.gitignore`

**Interfaces:**
- `validateBootstrap({ version, ref, commit, mainCommit, existingTags })` returns `{ allowed, reason }` and allows only version `0.3.2`, `refs/heads/main`, the current `main` SHA, and an empty stable-tag list.
- `createSourceArchive({ version, commit, outputDir })` returns `{ archivePath, checksumPath }`; archive contents are taken from that exact Git commit.

- [ ] **Step 1: Write bootstrap-policy tests** for the valid initial dispatch and wrong version, non-main branch, stale commit, existing tag, and repeated bootstrap cases.
- [ ] **Step 2: Run the focused tests and confirm they fail for the expected missing policy module.**
- [ ] **Step 3: Implement the pure bootstrap policy and make all policy tests pass.** The policy must reject a tag that exists at another SHA and must never move an existing tag.
- [ ] **Step 4: Write archive tests** asserting the archive contains the expected tracked source at the requested commit, the checksum matches its bytes, and repeated generation is deterministic.
- [ ] **Step 5: Implement archive creation** using `git archive` and deterministic gzip metadata; place output under ignored `release/dist/`.
- [ ] **Step 6: Run `npm test`** and verify both archive and checksum tests pass.

### Task 3: Consolidate CI and gate releases on all required checks

**Files:**
- Modify: `.github/workflows/ci.yml`
- Remove: `.github/workflows/tests.yml`

**Interfaces:**
- Keep jobs named `validate` and `test`, with the existing matrix contexts `test (8.2)`, `test (8.3)`, and `test (8.4)`.
- The `release` job uses `needs: [validate, test]`, runs only for a push to `main` or a `workflow_dispatch` on `main`, and has job-scoped write permissions.
- The dispatch accepts only the initial version `0.3.2`; ordinary `main` pushes use semantic-release after the initial tag exists.
- A normal `main` push before `v0.3.2` exists skips publication; it must not derive a version from the untagged history.

- [ ] **Step 1: Add a pinned `actionlint` check to the existing `validate` job** and verify both current workflows pass it before consolidation.
- [ ] **Step 2: Move the PHP matrix and `main`/`develop` triggers into `ci.yml`** without changing the protected context names or PHP version set.
- [ ] **Step 3: Add the release job with explicit dependencies and event guards.** Keep `contents: read` as the workflow default; grant `contents: write`, `id-token: write`, `attestations: write`, and `artifact-metadata: write` only to the release job.
- [ ] **Step 4: Require the initial `v0.3.2` tag before enabling automatic version calculation**; normal pushes without that tag must exit without analyzing the untagged history.
- [ ] **Step 5: Add serialized release execution** and fail-closed checks for target tag/SHA mismatches. Keep PR workflows unable to write tags, releases, or attestations.
- [ ] **Step 6: Remove the superseded `tests.yml` only after equivalent CI coverage exists in `ci.yml`.**
- [ ] **Step 7: Run `actionlint .github/workflows/ci.yml`** and inspect the workflow diff to confirm all existing branch-protection contexts are preserved.

### Task 4: Wire initial and automatic publishing, provenance, and recovery

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `.releaserc.json`
- Modify: `release/scripts/plan-release.mjs`
- Modify: `release/scripts/bootstrap-policy.mjs`
- Modify: `release/scripts/create-source-archive.mjs`
- Create: `release/scripts/recovery-policy.mjs`
- Create: `release/test/recovery-policy.test.mjs`

**Interfaces:**
- The initial path validates `workflow_dispatch` input and ref, reruns the same required CI jobs, builds/attests the `v0.3.2` archive, then creates the release for the current `main` SHA.
- The automatic path gets `{ version, type, tag }` from `planNextRelease`, builds and attests the source archive/checksum, then runs semantic-release to create the matching immutable tag and GitHub Release.
- `planRecovery({ tag, expectedCommit, actualTagCommit, releaseExists, existingAssets, expectedAssets })` returns `complete`, `create-release`, or `upload-missing`; a conflicting SHA or same-name asset with a different digest is rejected.
- Existing same-SHA publication can be safely resumed without changing the tag; a conflicting SHA fails closed.

- [ ] **Step 1: Add tests for no-release, same-SHA completed retry, missing-release recovery, missing-asset recovery, and conflicting-SHA behavior.** Confirm tests fail before adding workflow behavior.
- [ ] **Step 2: Implement the guarded `0.3.2` dispatch path** and assert the workflow skips it unless the ref is `main`, all checks pass, no earlier release tag exists, and the input is exactly `0.3.2`.
- [ ] **Step 3: Implement the automatic path** so it skips when semantic-release returns no version and uses the exact computed tag for both the archive and GitHub Release.
- [ ] **Step 4: Add `actions/attest` after archive/checksum creation** with only the permissions required for OIDC signing and artifact metadata; configure the GitHub release plugin to attach the archive and checksum.
- [ ] **Step 5: Add safe recovery** for a tag already pointing to the expected commit: create a missing GitHub Release against that existing tag or upload only missing assets. If an asset with the expected name has a different digest, fail instead of replacing it. Reject a tag pointing elsewhere.
- [ ] **Step 6: Run release tooling tests, `actionlint`, and a semantic-release dry run** against a tagged fixture repository containing the tested commit types; confirm no external tag or release is created.

### Task 5: Document and exercise the Packagist and operator workflow

**Files:**
- Modify: `README.md`
- Modify: `CONTRIBUTING.md`
- Create: `release/README.md`

- [ ] **Step 1: Document the actual CI/release triggers and Conventional Commit mapping** in `CONTRIBUTING.md`; remove claims that are not yet implemented.
- [ ] **Step 2: Document one-time Packagist registration and GitHub webhook setup** in `release/README.md`; keep Packagist credentials and webhook secrets out of the repository.
- [ ] **Step 3: Document initial bootstrap, same-SHA recovery, and provenance verification** using `gh attestation verify` for the published source archive.
- [ ] **Step 4: Update the README release-integrity section** to link to the runbook and explain that the initial package registration is an operator prerequisite.
- [ ] **Step 5: Run Markdown lint or the repository's available docs checks**, inspect all links, then run the complete CI-equivalent checks on the branch.

### Task 6: Publish and verify the first Composer release

**Prerequisites:** The implementation PR is merged with all required CI checks green; `tusk/framework` is registered on Packagist; Packagist's GitHub hook is enabled.

- [ ] **Step 1: Update local `main` from `origin/main` and verify the merged commit and required checks.**
- [ ] **Step 2: Dispatch the initial-release workflow on `main` with version `0.3.2`.** Do not create or push the tag manually.
- [ ] **Step 3: Verify GitHub tag/release `v0.3.2`, archive checksum, and provenance attestation**; reject any result that targets a different commit.
- [ ] **Step 4: Verify Packagist exposes `tusk/framework` version `0.3.2`** using `composer show tusk/framework --all` from a clean app checkout.
- [ ] **Step 5: Run `composer install`, the app PHPUnit suite, and the Engine RoadRunner skeleton smoke test**; confirm the original app CI dependency-install blocker is gone.

## Self-Review

- Spec coverage: bootstrap/version policy → Tasks 1–2 and 4; CI consolidation and protected contexts → Task 3; immutable releases and provenance → Task 4; Packagist/operator steps → Tasks 5–6; app/Engine integration acceptance → Task 6.
- Failure cases: PR writes, failed matrix, non-release commits, invalid bootstrap, partial publication, conflicting tag, and Packagist delay each have explicit checks or acceptance steps.
- Scope is limited to release automation and the single initial Framework package publication; no runtime packages, Engine release changes, or alternative distribution registries are included.

## References

- semantic-release configuration and dry-run: <https://semantic-release.gitbook.io/semantic-release/usage/configuration>
- semantic-release JavaScript API: <https://semantic-release.gitbook.io/semantic-release/developer-guide/js-api>
- GitHub artifact attestations: <https://github.com/actions/attest>
- Packagist submission and update hooks: <https://packagist.org/about>
