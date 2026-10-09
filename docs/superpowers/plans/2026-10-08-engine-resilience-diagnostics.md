# Framework–Engine Resilience Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface truthful, sanitized Framework resilience policy and circuit state in Tusk Engine metadata through a private, authenticated, per-worker diagnostics channel.

**Architecture:** Framework owns and reports the effective policy/runtime state; Engine accepts bounded loopback snapshots, aggregates them in memory, and projects an additive versioned metadata block. The Engine and Framework ship in separate PRs, Engine first, followed by a real Engine-managed RoadRunner integration run in the disposable lab.

**Tech Stack:** Go, PHP 8.2+, RoadRunner, Symfony/PHPUnit, existing Tusk control API and runtime lifecycle.

**Spec:** `docs/superpowers/specs/2026-10-08-engine-resilience-diagnostics-design.md`

## Global Constraints

- Preserve `origin/main` as the starting point in each repository; confirm a clean baseline before creating feature branches.
- Use lowercase kebab-case branch names with `feature/` or `docs/`; never use `codex/`.
- All repository changes go through PRs; do not push changes directly to `main`.
- Framework remains the source of truth; Engine aggregates diagnostics and never executes PHP policies.
- Keep existing Framework programmatic resilience APIs and Engine metadata v1 fields backward compatible.
- Do not add a mandatory package, public Framework diagnostics route, disk persistence, proxy, or gateway.
- Private ingestion binds only to `127.0.0.1`, uses a fresh per-run cryptographic bearer token, and is enabled only with Engine control metadata.
- Never log or expose the token, request body, policy values, throwable names, request data, worker PID, or worker identifier.
- Enforce 64 KiB request size, at most 256 policy entries and 256 circuit entries per report, 128 UTF-8 bytes per name, and at most 4,096 retained workers.
- Worker reports use monotonically increasing sequence numbers scoped to one Engine run; a fresh token/listener and empty store are created for every run. Engine receipt time is authoritative; reports expire after a 60-second lease.
- Framework reporter makes one attempt with a 50 ms timeout; request/job heartbeat is coalesced to at most once per 15 seconds; failures never change business outcomes.
- A control listener that is non-loopback omits policy and circuit names from metadata while preserving aggregate state counts.
- Report `unavailable`, `fresh`, `stale`, and per-worker `unknown` honestly; never represent stale data as live state.

## Review Focus

- **Forged or replayed report:** reject bad tokens and non-increasing sequence numbers without changing the last accepted snapshot; pin with Engine HTTP tests in Task 1.
- **Partial, oversized, or malformed snapshot:** reject atomically; one invalid field must not erase another worker's state; pin payload and validation cases in Task 1.
- **Worker restart or idle worker:** old state becomes `unknown` after the lease, while a fresh worker's state remains intact; pin in Tasks 1 and 3.
- **Non-loopback metadata request:** omit all policy/circuit identifiers and sensitive values while preserving counts; pin in Task 1.
- **Reporter unavailable or slow:** keep application request/job results unchanged and send no more often than the heartbeat bound; pin in Task 2 and validate end-to-end in Task 3.

---

### Task 1: Engine private ingest, aggregation, and metadata projection

**Repository:** `tusk-engine`

**Files:**
- Create: `internal/control/resilience_diagnostics.go` — bounded per-worker snapshot store and deterministic freshness aggregation.
- Create: `internal/control/resilience_ingest.go` — loopback-only listener, token authentication, bounded JSON handler, and lifecycle.
- Modify: `internal/control/metadata.go` — additive `application.resilience` projection with remote redaction.
- Modify: `internal/control/server.go` — start/stop the private receiver with the control plane and include its live projection in metadata.
- Modify: `internal/cli/cli.go` — create the per-run receiver before RoadRunner, then pass only its URL/token in `ProcessSpec.Env` when control is enabled.
- Test: `internal/control/resilience_diagnostics_test.go`, `internal/control/resilience_ingest_test.go`, `internal/control/metadata_test.go`, `internal/control/server_test.go`, and `internal/cli/cli_test.go`.

**Interfaces:**
- `WorkerResilienceReport` mirrors the spec's `schema_version`, opaque `worker_id`, `sequence`, `policies`, and `circuits` fields.
- `ResilienceDiagnosticsStore.Record(report WorkerResilienceReport, receivedAt time.Time) error` validates and atomically replaces one worker's report; the store is owned by one listener/run and is never reused across Engine restarts.
- `ResilienceDiagnosticsStore.Snapshot(now time.Time, exposeNames bool) ResilienceSummary` returns a copied, sorted, aggregated value; stale worker circuit states become `unknown`.
- `ResilienceIngestServer` binds `127.0.0.1:0` and exposes its URL/token only to the Engine process before RoadRunner starts; the already-bound listener is served by `Start` and shut down by `Stop`. No separate readiness race is introduced after bind.
- `POST /internal/v1/resilience/snapshot` returns a small response and never echoes the submitted payload.

- [ ] **Step 1: Write failing store tests** for valid worker isolation, report replacement, duplicate/out-of-order sequence rejection, atomic invalid report rejection, deterministic aggregate counts, and 60-second expiry.
- [ ] **Step 2: Run** `go test ./internal/control -run 'ResilienceDiagnostics'`; confirm failures identify the missing store behavior.
- [ ] **Step 3: Implement** the immutable report types, bounds, in-memory store, freshness transition, and non-loopback redaction inputs.
- [ ] **Step 4: Write failing HTTP tests** for loopback bind, valid bearer, wrong/missing token, oversized body, unsupported schema, invalid names/states, and response-body redaction.
- [ ] **Step 5: Run** the focused HTTP tests and confirm they fail for the expected missing receiver behavior.
- [ ] **Step 6: Implement** the private receiver using a cryptographic per-run token and constant-time comparison; instantiate a new empty store per listener/run, do not log tokens or bodies, and ensure shutdown closes the listener and clears the store before any next run can accept reports.
- [ ] **Step 7: Write failing metadata/lifecycle tests** for unavailable/fresh/stale summaries, additive v1 fields, non-loopback name redaction, private listener stop/reset and fresh-run isolation, and control-disabled behavior.
- [ ] **Step 8: Wire** the already-bound receiver URL/token into the RoadRunner process environment before startup; verify they are absent when control is disabled and are not written to `.rr.yaml`.
- [ ] **Step 9: Run** `go test ./...`, `go vet ./...`, and `go build ./cmd/tusk`; fix all introduced failures.
- [ ] **Step 10: Open Engine PR** with the repository template, link Framework #10, and keep it separate from the Framework implementation PR.

### Task 2: Framework runtime registry, snapshots, and no-op-safe reporter

**Repository:** `tusk-framework` (start a new feature branch only after synchronizing its local `main`; use a branch such as `feature/resilience-engine-diagnostics`).

**Files:**
- Create: `tusk-cloud/src/Resilience/Diagnostics/ResilienceDiagnosticsRegistry.php` — worker-local active policies and current circuit-state snapshot.
- Create: `tusk-cloud/src/Resilience/Diagnostics/ResilienceDiagnosticsSnapshot.php` — immutable sanitized snapshot DTO.
- Create: `tusk-cloud/src/Resilience/Diagnostics/ResilienceRuntime.php` — configuration-aware `pipeline(string $name)` facade and `diagnostics()` API.
- Create: `tusk-cloud/src/Resilience/Diagnostics/EngineResilienceReporter.php` — optional environment-configured HTTP reporter with worker identity, sequence, redaction, timeout, and coalesced heartbeat.
- Create: `tusk-contracts/src/Observability/WorkerLifecycleCheckpointInterface.php` — generic optional lifecycle callback contract.
- Modify: `tusk-cloud/src/Resilience/ResiliencePipelineFactory.php` and `ResilienceInstrumentation.php` — register resolved feature sets and accepted circuit transitions without changing policy execution.
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php` — bind one worker-local `ResilienceRuntime`, registry, and no-op-or-Engine reporter after config validation.
- Modify: `tusk-runtime/src/Observability/RuntimeObservability.php` and `RuntimeObservabilityModule.php` — call the generic checkpoint at worker start, request/job completion, and worker stop without depending on the Cloud package.
- Modify: `tusk-cloud/README.md` — document the container API, state scope, Engine integration, privacy, lease, and failure behavior.
- Test: diagnostics tests under `tusk-cloud/tests/Resilience/Diagnostics/`; update `tusk-core/tests/Foundation/ApplicationBuilderTest.php` and `tusk-runtime/tests/Observability/RuntimeObservabilityTest.php`.

**Interfaces:**
- `ResilienceRuntime::pipeline(string $name): ResiliencePipelineBuilder` resolves a name from the active profile and records only the effective policy name/features, never its settings.
- `ResilienceRuntime::diagnostics(): ResilienceDiagnosticsSnapshot` returns a detached point-in-time snapshot; a configured circuit is `unknown` until its worker-local store confirms a state.
- The reporter no-ops unless both `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL` and `TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN` exist and the URL is exactly an allowed IPv4 loopback HTTP address.
- Direct `new ResiliencePipelineFactory(...)` use remains compatible and does not claim to be Engine-managed.
- Reporter failures, timeouts, and malformed responses are swallowed independently of policy results; no retry is attempted.

- [ ] **Step 1: Write failing tests** for `ResilienceRuntime` resolution, unknown names, effective-profile features, immutable snapshots, active circuit state, and omission of policy settings/throwable names.
- [ ] **Step 2: Run** focused diagnostics tests; confirm the failures are for missing runtime/registry behavior.
- [ ] **Step 3: Implement** the registry and immutable snapshot DTO; update it only after accepted policy resolution or circuit-state transitions.
- [ ] **Step 4: Write failing reporter tests** for absent/partial env, non-loopback URL rejection, worker ID and increasing sequence, payload bounds, 50 ms timeout, transition snapshots, and 15-second heartbeat coalescing.
- [ ] **Step 5: Implement** the reporter with one request attempt, redirects disabled, no payload logging, and no effect on the original operation result; bind report sequence and worker identity to the lifetime of that PHP process.
- [ ] **Step 6: Write failing lifecycle tests** proving initial report at worker start and checkpoints after HTTP request, job, and worker stop; prove reporter failure does not fail the lifecycle operation.
- [ ] **Step 7: Implement** the generic contract in `tusk-contracts` and wire it through runtime observability without adding a Runtime→Cloud dependency.
- [ ] **Step 8: Write failing application-container tests** proving `ResilienceRuntime` is bound from validated active-profile config and direct factory APIs remain unchanged.
- [ ] **Step 9: Bind the worker-local runtime** in `ApplicationBuilder` after validation and before providers/routes are loaded; preserve existing providers and runtime module ordering.
- [ ] **Step 10: Run** focused PHPUnit, full `php vendor/phpunit/phpunit/phpunit --testdox`, Pint on touched files, PHPStan, `composer validate --strict`, and `git diff --check`.
- [ ] **Step 11: Open Framework PR** only after Engine's receiver contract is merged; update from `origin/main` and port the tested changes to a fresh feature branch if the existing branch's base moved.

### Task 3: Real cross-repository RoadRunner verification in the lab

**Repository:** `tusk-engine-lab`

**Files:**
- Modify: `scripts/run-all.ps1` and `scripts/run-all.sh` — add a resilience diagnostics stage to both supported entry points.
- Create: `scripts/stages/resilience.ps1` and `scripts/stages/resilience.sh` — disposable fixture setup, Engine start, trigger, metadata assertions, worker restart/staleness, and cleanup.
- Modify: `scripts/templates/` only for the dedicated resilience fixture controller/provider and `config/resilience.php`.
- Modify: `README.md` — stage and prerequisite documentation.

**Interfaces:**
- The stage uses local-source Engine and Framework paths, one RoadRunner HTTP worker for deterministic circuit transitions, and the Engine's authenticated local `/v1/metadata` endpoint. It may run against staged PR commits before merge, then rerun against merged `main` commits for final evidence.
- The fixture's policy is named and configured in `config/resilience.php`; it is resolved through injected `ResilienceRuntime`, not a hand-built diagnostic payload.
- The test never uses public diagnostics routes, production credentials, or persistent state; all generated runtime state remains in the lab directory and is cleaned in `finally`/trap handlers.

- [ ] **Step 1: Add a fixture test controller/provider** that can deterministically move a configured breaker through closed, open, half-open, and closed states.
- [ ] **Step 2: Run the stage** against the unmodified lab and confirm it fails because metadata lacks the resilience block.
- [ ] **Step 3: Add the PowerShell and Git Bash stage** with exact process cleanup and assertions for active policy names, per-worker states, timestamp/freshness, and redaction.
- [ ] **Step 4: Exercise worker restart and lease expiry**; assert the old observation becomes unknown and a new worker's state does not inherit the old one.
- [ ] **Step 5: Run the focused stage** in local-source mode on this host; retain failure artifacts only when the stage fails.
- [ ] **Step 6: Run all lab stages** in PowerShell and Git Bash where available; report platform-specific skips instead of claiming unrun checks.
- [ ] **Step 7: Open a separate lab PR** after Engine and Framework implementation PRs are available; link both implementation PRs and keep it focused on the integration harness. Run it against those PR commits before merge, then rerun against merged `main` commits for final evidence.

### Task 4: Merge, reconcile tracking, and final verification

- [ ] **Step 1: Review Engine PR and all checks**; fix failures on its branch and merge only when checks are green.
- [ ] **Step 2: Synchronize Framework `main` from `origin/main`**, verify clean baseline, then rebase/port the Framework feature and resolve any dependency changes safely.
- [ ] **Step 3: Re-run Framework full suite and CI-equivalent checks** after the Engine contract merge; update and merge the Framework PR only when green.
- [ ] **Step 4: Run the cross-repository lab** against the merged Engine and Framework mains and record exact results in the lab PR.
- [ ] **Step 5: Merge the lab PR** when its tests and review are green.
- [ ] **Step 6: Update and close Framework issue #10** with Engine, Framework, and lab PR links only after every acceptance criterion has evidence; leave declarative work in #11/#12 open.

## Final verification and handoff

- Run Framework PHPUnit, Pint, PHPStan, Composer validation, and `git diff --check` from a clean feature worktree.
- Run Engine `go test ./...`, `go vet ./...`, `go build ./cmd/tusk`, and `git diff --check` from a clean feature worktree.
- Run the actual Engine-managed RoadRunner lab and inspect metadata transitions and expiry; unit tests alone do not satisfy the cross-repository requirement.
- Verify no token, payload, policy values, worker identity/PID, or throwable names appear in logs or metadata.
- Confirm all PR checks are green and each PR targets the current `main` of its own repository before merging.
