# Resilience Configuration and Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add typed, validated named resilience configuration, integrate it with boot/CLI/readiness, and prove safe persistent-worker behavior.

**Architecture:** Add immutable configuration value objects and a loader/resolver in `tusk-cloud` that compose the existing resilience policy factories and `ResiliencePipelineFactory`; do not alter policy execution. Then connect validation to the existing application/CLI lifecycle and add local readiness reporting, preserving the current rule that liveness does not invoke health checks.

**Tech Stack:** PHP 8.2+, PHPUnit, PHPStan, Pint, Symfony Console, existing Tusk Config/Cloud/Runtime/CLI packages.

**Spec:** `docs/superpowers/specs/2026-10-08-resilience-configuration-design.md`

## Global Constraints

- Preserve the existing programmatic APIs and PSR contracts.
- Do not change default retry safety or request-body replay rules.
- Avoid new mandatory dependencies.
- Keep existing health endpoints/contracts compatible; readiness additions are additive.
- Invalid configuration fails fast with a stable, actionable diagnostic naming the policy and configuration path, without exposing secret values.
- Liveness does not call external dependencies; readiness reports local boot/configuration state only.
- Persistent-worker tests prove no request-scoped state leaks between operations.
- Declarative attributes/compiled metadata remain tracked in #11/#12; no runtime-reflection magic in this change.
- No proxy/gateway behavior, RoadRunner request-path changes, mandatory telemetry providers, or Engine diagnostics.

## Review Focus

- Profile overlays replacing an entire named policy instead of merging its individual fields must have a pinned, deterministic test.
- Unknown policy names/keys and malformed nested values must produce actionable paths without leaking supplied values.
- Invalid exception class names in classifier configuration must fail validation, not silently disable retry classification.
- `tusk config:validate` must not boot workers, make network calls, or mutate generated/runtime state.
- Readiness must include local configuration validity while liveness stays independent of registry checks and remote services.

## Intended PRs

1. **PR 1 — typed named policy configuration:** immutable configuration DTOs, profile overlay/binding, validation, resolver to existing builders, focused tests and examples.
2. **PR 2 — framework lifecycle integration:** boot and `tusk config:validate`, readiness integration, persistent-worker isolation tests, and operator documentation.

## File map

- Create `tusk-cloud/src/Resilience/Configuration/ResilienceConfiguration.php` — immutable collection of named policies.
- Create `tusk-cloud/src/Resilience/Configuration/ResiliencePolicyConfiguration.php` — immutable optional settings for retry, breaker, bulkhead, and rate limit.
- Create `tusk-cloud/src/Resilience/Configuration/ResilienceConfigurationLoader.php` — strict array binding, profile overlay, and validation diagnostics.
- Create `tusk-cloud/src/Resilience/Configuration/ResiliencePipelineResolver.php` — convert one configured name plus `ResiliencePipelineFactory` and optional `RandomSourceInterface` into a configured builder.
- Create `tusk-cloud/src/Resilience/Configuration/ConfiguredFailureClassifier.php` — implement the existing classifier contract, make deny rules precede allow rules, and delegate to `DefaultFailureClassifier` when no allow list is configured.
- Add focused tests under `tusk-cloud/tests/Resilience/Configuration/` for loading, overlay, validation, and resolution.
- Modify `tusk-core/src/Foundation/ApplicationBuilder.php` — validate resilience config before runtime modules are created and bind typed configuration into the application container.
- Create `tusk-cli/src/Commands/ConfigValidateCommand.php` and register it as a core command in `bin/tusk`, available before `.tusk` compilation.
- Add `tusk-cloud/src/Health/ResilienceConfigurationHealthCheck.php` and register it through `ApplicationBuilder` and the existing `HealthCheckRegistry` lifecycle.
- Add/update lifecycle and CLI tests in their established package test directories; update `tusk-cloud/README.md` and the generated config stub only where needed for the recommended setup.

---

### Task 1: Typed immutable named policy model

**Files:**
- Create: `tusk-cloud/src/Resilience/Configuration/ResiliencePolicyConfiguration.php`
- Create: `tusk-cloud/src/Resilience/Configuration/ResilienceConfiguration.php`
- Test: `tusk-cloud/tests/Resilience/Configuration/ResilienceConfigurationTest.php`

**Interfaces:**
- Produces `ResiliencePolicyConfiguration::fromArray(string $name, array $values): self` and read-only accessors for the optional retry, circuit-breaker, bulkhead, and rate-limit maps.
- Produces `ResilienceConfiguration::fromArray(array $policies): self`, `policy(string $name): ?ResiliencePolicyConfiguration`, and `all(): array<string, ResiliencePolicyConfiguration>`.
- Names are non-empty trimmed strings; unknown keys and wrong scalar/list/object types throw `InvalidArgumentException` whose message identifies the full configuration path and never echoes user-provided values.

- [ ] **Step 1: Write failing tests** for a valid named policy, empty configuration, invalid blank name, unknown top-level/policy key, and wrong nested value type.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter ResilienceConfigurationTest`. Expected: new tests fail because the configuration classes are absent.
- [ ] **Step 3: Implement** the two immutable configuration classes with strict key/type validation; do not construct runtime pipeline objects in these DTOs.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter ResilienceConfigurationTest`. Expected: all configuration model tests pass.
- [ ] **Step 5: Run** `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/pint --test <changed PHP files>`; fix any introduced findings.
- [ ] **Step 6: Commit** as `feat: add typed resilience configuration model`.

### Task 2: Profile-aware binding and deterministic policy resolution (PR 1)

**Files:**
- Create: `tusk-cloud/src/Resilience/Configuration/ResilienceConfigurationLoader.php`
- Create: `tusk-cloud/src/Resilience/Configuration/ResiliencePipelineResolver.php`
- Create if required by tests: `tusk-cloud/src/Resilience/Configuration/ConfiguredFailureClassifier.php`
- Test: `tusk-cloud/tests/Resilience/Configuration/ResilienceConfigurationLoaderTest.php`
- Test: `tusk-cloud/tests/Resilience/Configuration/ResiliencePipelineResolverTest.php`

**Interfaces:**
- Consumes Task 1's `ResilienceConfiguration` and `ResiliencePolicyConfiguration`.
- Produces `ResilienceConfigurationLoader::load(array $values, ?string $profile = null): ResilienceConfiguration`.
- Produces `ResiliencePipelineResolver::resolve(string $name, ResilienceConfiguration $configuration, ResiliencePipelineFactory $factory): ResiliencePipelineBuilder`; unknown names throw actionable `InvalidArgumentException`.
- Loader input is the array returned from `config/resilience.php`, with `policies.<name>` plus optional `profiles.<profile>.policies.<name>`. A profile policy shallow-overrides matching policy fields and leaves unspecified base fields intact; profile-only policy names are included. Profile and policy keys are strict.
- Supported policy keys: `retry` (`max_attempts`, `backoff` with `type`, `base_delay_ms`, `max_delay_ms`, optional `allow_unsafe_retries`), `circuit_breaker` (`failure_threshold`, `open_duration_ms`, `half_open_probe_limit`), `bulkhead` (`max_concurrent`, `max_queued`), and `rate_limit` (`capacity`, `refill_per_second`, `max_wait_ms`). `backoff.type` accepts `fixed`, `exponential`, or `decorrelated_jitter`; jitter uses the existing injectable random source/default secure source.
- Retry classification accepts optional `retry_on` and `do_not_retry_on` lists of existing throwable class names; every entry must exist and implement `Throwable`. Deny rules take precedence; when a non-empty allow list exists only matching failures retry; when omitted, preserve `DefaultFailureClassifier` behavior. Retry remains disabled for unsafe contexts unless `allow_unsafe_retries` is explicitly true, preserving current `RetryPolicy` defaults.
- A policy without a section leaves that existing pipeline feature disabled; no hidden defaults activate policies.

- [ ] **Step 1: Write failing tests** for base loading, profile field merge, profile-only operation, backoff kinds, exception allow/deny precedence, invalid bounds/classes, unknown name, and exact resolution into builder behavior.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'ResilienceConfigurationLoaderTest|ResiliencePipelineResolverTest'`. Expected: tests fail because loader/resolver do not exist.
- [ ] **Step 3: Implement** the loader and resolver by mapping to `RetryPolicy`, backoff classes, `CircuitBreakerPolicy`, `BulkheadPolicy`, and `RateLimitPolicy`; keep existing execution internals unchanged.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'ResilienceConfiguration|ResiliencePipelineResolver|ResiliencePipeline|Psr18ResilientClient'`. Expected: all pass; existing safety/replay tests remain unchanged and green.
- [ ] **Step 5: Run** `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test <changed PHP files>`, and `composer validate --strict`. Expected: no findings.
- [ ] **Step 6: Commit** as `feat: resolve named resilience policies`.
- [ ] **Step 7: Open PR 1** with the repository PR template and explain the accepted profile merge semantics and compatibility constraints.

### Task 3: Boot-time validation and side-effect-free CLI command (PR 2)

**Files:**
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php` — choose active profile, validate config, and bind typed configuration.
- Create: `tusk-cli/src/Commands/ConfigValidateCommand.php` — reusable Symfony command for project config.
- Modify: `bin/tusk` — register command as built-in alongside `build`, before compiled application commands load.
- Test: `tusk-core/tests/Foundation/ApplicationBuilderTest.php`, `tusk-cli/tests/Commands/ConfigValidateCommandTest.php`, and `tests/Integration/ConfigValidateCommandIntegrationTest.php`.

**Interfaces:**
- Consumes Task 2's `ResilienceConfigurationLoader` and repository config source.
- CLI name: `config:validate`; optional `--profile=<name>` selects the profile. Application boot uses `APP_ENV`, default `production`, and invokes the same loader.
- Success prints a concise confirmation and returns `Command::SUCCESS`; invalid config prints policy/path diagnostic, does not print raw values, and returns `Command::FAILURE`.
- Validation parses local config only: no RoadRunner startup, worker creation, network requests, or generated-file writes.

- [ ] **Step 1: Write failing command tests** for valid default profile, valid explicit profile, invalid policy diagnostic/exit status, redaction, and proof no runtime/network side effect is invoked.
- [ ] **Step 2: Run** focused CLI tests. Expected: tests fail because command or profile-aware validation wiring is missing.
- [ ] **Step 3: Implement** project config loading with the same direct PHP array-file rules as `ApplicationBuilder`, without constructing `Application`, container, runtime modules, or RoadRunner.
- [ ] **Step 4: Add failing boot test** proving invalid resilience configuration aborts before runtime starts; run it and confirm failure.
- [ ] **Step 5: Implement** boot-time validation with the same loader/profile selection and actionable exception, before worker traffic is accepted.
- [ ] **Step 6: Run** `vendor/bin/phpunit --filter 'ConfigValidateCommand|ApplicationBuilderTest'`. Expected: success and invalid-config paths pass with no runtime side effects.
- [ ] **Step 7: Commit** as `feat: validate resilience policies during boot`.

### Task 4: Readiness, persistent-worker isolation, and operational documentation (PR 2)

**Files:**
- Create: `tusk-cloud/src/Health/ResilienceConfigurationHealthCheck.php`.
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php` — register the local health contributor.
- Test: `tusk-cloud/tests/Health/ResilienceConfigurationHealthCheckTest.php`, `tusk-cloud/tests/Controller/HealthControllerTest.php`, and `tests/Integration/RuntimeBootstrapIntegrationTest.php`.
- Modify: `tusk-cloud/README.md` and `tusk-cli/stubs/config-app.stub` only for the supported recommended configuration/example.

**Interfaces:**
- Health contributor implements `HealthCheckInterface::getName(): string` and `check(): bool`, reports local validated-configuration readiness, and performs no external calls.
- Liveness remains the current unconditional local `UP` response and must not call `HealthCheckRegistry::runChecks()`.
- Resilience runtime state stays scoped to named runtime components as already implemented; no request-local context is retained in shared config objects.

- [ ] **Step 1: Write failing health tests** proving successfully validated local config reports UP, invalid config prevents application boot (covered jointly with Task 3), liveness remains UP without invoking the registry, and the health check does not access remote dependencies.
- [ ] **Step 2: Run** focused health tests. Expected: new readiness contributor behavior fails before implementation.
- [ ] **Step 3: Implement** additive local readiness integration through the existing registry; do not change response fields for existing checks.
- [ ] **Step 4: Add failing persistent-worker lifecycle test** running two requests with different operation contexts and asserting the second does not inherit the first request's context or telemetry attributes.
- [ ] **Step 5: Run** lifecycle test and verify it fails for the expected state-leak assertion if leakage exists; if existing architecture already prevents it, document the passing invariant and do not add production code solely for coverage.
- [ ] **Step 6: Document** minimal named policy/profile configuration, validation command, safe retry defaults, failure classification, readiness/liveness distinction, and persistent worker behavior.
- [ ] **Step 7: Run** `vendor/bin/phpunit --testdox`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test <changed PHP files>`, and `composer validate --strict`. Expected: all checks pass.
- [ ] **Step 8: Commit** as `feat: expose resilience configuration readiness`.
- [ ] **Step 9: After PR 1 is merged, update local `main` from `origin/main`, verify it clean, create `feature/resilience-configuration-lifecycle`, and port only Task 3/4 changes onto it.
- [ ] **Step 10: Open PR 2** targeting `main`, referencing PR 1 and issue #10; use `.github/pull_request_template.md`.

## Final verification and handoff

- Run the full repository test suite and every CI-equivalent command after PR 2 changes.
- Inspect `git diff --check`, branch status, and PR checks before reporting.
- Do not merge until both PRs are green; merge in dependency order if the user’s standing instruction to merge green PRs applies.
- Do not close resilience epic #10 until all acceptance criteria, including declarative work tracked in #11/#12 if still included there, are reconciled explicitly.
