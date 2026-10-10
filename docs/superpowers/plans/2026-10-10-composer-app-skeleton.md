# Composer Application Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a Composer-installable Tusk application skeleton and make it the single documented path from project creation to a RoadRunner-served typed endpoint.

**Architecture:** Keep `tusk/framework` as a library and create `tusk/app` in `tusk-framework/tusk-app` as the Composer `project` package. Composer owns creating/installing the application; the Engine owns toolchain/runtime setup and keeps `tusk init` for existing projects; the Framework keeps application/runtime commands but removes its duplicate project generator. Coordinate the skeleton, Framework cleanup, docs, and release-level smoke test through separate PRs in their owning repositories.

**Tech Stack:** PHP 8.2+, Composer, PHPUnit, Tusk Framework, Tusk Engine (Go), RoadRunner, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-10-composer-app-skeleton-design.md`

## Global Constraints

- Composer project package name is `tusk/app`; GitHub repository is `tusk-framework/tusk-app`.
- Framework library remains `tusk/framework`; generated projects require a stable released Framework constraint, not `dev-main`.
- PHP floor remains `^8.2` unless release metadata explicitly changes it.
- `tusk init` in the Engine only creates Engine configuration in an existing project; it does not create an application.
- RoadRunner owns workers, transport, pools, IPC, and process supervision; Engine controls lifecycle; PHP Framework owns application behavior.
- New branches use conventional lowercase kebab-case names and never `codex/`.
- Keep all changes in isolated worktrees and submit each repository's changes through its own PR.
- Do not overwrite existing project files, embed `vendor/`, commit secrets, or add legacy worker/config bootstrap paths.

## Review Focus

- **Composer package/version resolution:** verify `tusk/app` installs a released `tusk/framework` on Packagist and does not resolve a development branch.
- **Project-root and bootstrap paths:** verify generated paths and namespace mappings work from the project root on Windows and Unix-like systems.
- **Engine configuration ownership:** verify `tusk.json`/`.rr.yaml` are valid for the supported Engine while `tusk init` remains configuration-only.
- **No legacy entrypoint fallback:** verify only `bootstrap/app.php` is used and invalid/missing configuration fails before RoadRunner starts.
- **Example behavior and process failure:** verify the typed endpoint's validation/Problem Details contract and preserve errors/exit status through the documented command path.

---

### Task 1: Create and verify the Composer project skeleton

**Repository:** `tusk-framework/tusk-app` (new public repository)

**Files:**
- Create: `composer.json` — project identity, PHP floor, stable `tusk/framework` requirement, autoloading, and minimal developer scripts.
- Create: `bootstrap/app.php`, `bootstrap/providers.php`, `public/index.php`, `routes/web.php`, and the minimal application source for the typed HTTP example.
- Create: `tusk.json`, `.rr.yaml`, `.gitignore`, `.env.example`, `README.md`, and `LICENSE` as needed for a distributable skeleton.
- Create: `tests/SkeletonTest.php` and `.github/workflows/ci.yml` — assert required files, manifest/package constraints, supported bootstrap, and absence of legacy worker/config entrypoints.
- Release prerequisite: register `tusk/app` with Packagist and configure package updates; if the organization account cannot be accessed in this environment, stop before publishing and request the owner to complete registration.

**Interfaces:**
- Consumes: released Framework API from `tusk/framework` v0.3.2 or a later compatible release; the documented Engine project configuration contract.
- Produces: a self-contained Composer project usable via `composer create-project tusk/app <directory>`.

- [ ] **Step 1: Add a testable project manifest and failing tests** `test_manifest_is_a_composer_project_requiring_stable_tusk_framework`, `test_supported_bootstrap_and_application_files_exist`, and `test_legacy_bootstrap_entrypoints_are_absent` in `tests/SkeletonTest.php`; the test harness may exist, but required application files must not yet be present.
- [ ] **Step 2: Run** `composer install --no-interaction` followed by `composer test`; verify application-file/bootstrap contract assertions fail.
- [ ] **Step 3: Add the minimal skeleton** based on the currently supported bootstrap and typed CRUD guide; do not copy Framework internals or `vendor/`.
- [ ] **Step 4: Verify the package** with `composer validate --strict`, `composer install --no-interaction`, `composer test`, and a focused Framework contract test for the typed HTTP example.
- [ ] **Step 5: Commit and open a PR** in `tusk-framework/tusk-app`; keep this PR independent of the Framework cleanup.

### Task 2: Remove duplicate project creation from the Framework

**Repository:** `tusk-framework/tusk-framework`

**Files:**
- Modify: `tusk-cli/src/Command/FrameworkCommandCatalog.php` — remove the Framework `init` registration.
- Delete: `tusk-cli/src/Commands/InitCommand.php`, `tusk-cli/src/Generator/ProjectGenerator.php`, and only the project-generation stubs now owned by `tusk/app`.
- Modify: `tusk-cli/tests/Command/FrameworkCommandCatalogTest.php`, `tusk-cli/tests/Command/FrameworkCommandLoaderTest.php`, `tusk-cli/tests/Generator/ProjectGeneratorTest.php`, and `tests/Integration/ConsoleIntegrationTest.php` to assert the new command contract and remove generator-specific expectations.

**Interfaces:**
- Consumes: the initial skeleton package PR establishes the canonical application files and creation instructions.
- Produces: Framework CLI commands for working inside an existing Tusk application, with no competing project-creation `init`.

- [ ] **Step 1: Add** `test_project_creation_init_command_is_not_registered` to `FrameworkCommandCatalogTest` and update `test_generated_project_framework_commands_run_without_build_and_do_not_boot_the_application` to use a minimal test fixture rather than `ProjectGenerator`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tusk-cli/tests/Command/FrameworkCommandCatalogTest.php tests/Integration/ConsoleIntegrationTest.php`; verify the new command assertion fails against the current catalog.
- [ ] **Step 3: Remove the command, generator, and duplicate stubs**; retain controller/entity/migration generators and shared runtime/application examples only where they are not project templates. Delete `ProjectGeneratorTest.php` after migrating useful integration assertions to the new skeleton/CLI contracts.
- [ ] **Step 4: Run focused CLI/generator tests and the full PHPUnit suite**; verify no generated-project integration coverage was lost without replacement in the skeleton repository.
- [ ] **Step 5: Commit and open a Framework PR** linked to issue #9 and the skeleton PR.

### Task 3: Align Framework and Engine user documentation

**Repositories:** `tusk-framework/tusk-framework` and `tusk-framework/tusk-engine`

**Files:**
- Modify Framework `README.md`, `tusk-cli/README.md`, `docs/guides/typed-http-crud.md`, and migration docs/stubs where project setup is described.
- Modify Engine `README.md`, `docs/guides/project-runtime.md`, user-guide installation/runtime pages, `internal/cli` tests, and offline documentation fixtures/tests where setup or `tusk init` is described.

**Interfaces:**
- Consumes: the public `tusk/app` package name and supported Engine/Framework command behavior from Tasks 1–2.
- Produces: one consistent sequence for project creation, diagnostics, build, and start; a separate explanation of `tusk init` for existing projects.

- [ ] **Step 1: Add** `TestProjectCreationDocsUseComposerSkeleton` in Engine packaging tests and `TestRunInitCreatesOnlyTuskJSON` in Engine CLI tests for the canonical command and configuration-only `tusk init` semantics.
- [ ] **Step 2: Run** `go test ./internal/packaging -run TestProjectCreationDocsUseComposerSkeleton -count=1`; verify it fails against the current conflicting examples.
- [ ] **Step 3: Update Framework and Engine setup guides**; ensure all commands are present in the respective released CLIs and are shown in PowerShell/Git Bash-compatible form where needed.
- [ ] **Step 4: Run documentation/CLI test suites in both repositories** and search for stale `tusk init <name>` and project-generator references.
- [ ] **Step 5: Commit and open separate PRs** in Framework and Engine, cross-linking them and the skeleton PR.

### Task 4: Replace the Engine smoke's in-repository generator with the published skeleton

**Owner:** Engine integration workflow, coordinated with the skeleton and Framework releases.

**Files:**
- Modify: `tusk-engine/.github/workflows/test.yml` — stop checking out Framework `main` solely to generate the test application; consume the released Composer project package after it is published.
- Modify: `tusk-engine/test/skeleton/smoke.ps1` — replace the `ProjectGenerator` invocation and Framework-checkout prerequisites with `composer create-project tusk/app` and assert the released skeleton layout.
- Modify: `tusk-engine/internal/packaging/framework_contract_test.go` and relevant packaging/docs contract tests — assert the new skeleton package and command sequence rather than a Framework source ref/generator path.
- Modify: `tusk-engine/docs/guides/framework-smoke-contract.md` and related user/offline setup docs to describe the exact package refs exercised.

**Interfaces:**
- Consumes: released `tusk/app`, released `tusk/framework`, and the selected Engine build/release.
- Produces: release-level evidence for the documented Composer → Engine → RoadRunner → PHP Framework flow.

- [ ] **Step 1: Add** `TestFrameworkSmokeContractUsesComposerSkeleton` requiring `composer create-project tusk/app` and forbidding reliance on `ProjectGenerator` or a Framework source checkout for project creation.
- [ ] **Step 2: Run** `go test ./internal/packaging -run TestFrameworkSmokeContractUsesComposerSkeleton -count=1`; verify it fails against the existing `FRAMEWORK_REF`/`ProjectGenerator` workflow.
- [ ] **Step 3: Extend the smoke** to run Engine diagnostics, Framework build, RoadRunner start, an HTTP request to the typed example, and graceful shutdown; preserve command exit codes and logs on failure.
- [ ] **Step 4: Run the smoke against pinned published skeleton/Framework package versions and the selected Engine commit/release** on supported CI platforms; verify no fallback to a legacy PHP worker/config entrypoint.
- [ ] **Step 5: Commit and open the Engine integration PR**; close Framework issue #9 only after all linked PRs are merged and release-level evidence is green.

### Dependency and merge order

1. Merge the skeleton repository PR and publish the `tusk/app` package release.
2. Merge the Engine smoke/docs PR consuming that published skeleton, then verify it is green against the published Framework release.
3. Merge the Framework cleanup/docs PR after its CLI tests no longer depend on `ProjectGenerator`.
4. Close Framework issue #9 only after the release-level smoke runs green with the documented package and Engine versions.

## Final verification

- Run `composer validate --strict`, skeleton contract tests, Framework focused/full tests, and Engine focused/full Go tests for their respective PRs.
- Run the coordinated clean-project smoke from the actual Composer package and selected Engine artifact on every supported CI platform.
- Check the final docs from an empty directory against the exact released command set; verify the typed endpoint responds successfully and validation failures return the documented RFC 9457 response.
- Confirm `tusk init` remains configuration-only and that no Framework project generator, `dev-main` dependency, legacy bootstrap, or duplicate RoadRunner worker management remains.
