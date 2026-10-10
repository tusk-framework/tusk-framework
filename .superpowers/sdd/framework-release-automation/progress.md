# SDD ledger — plan: docs/superpowers/plans/2026-10-10-framework-release-automation.md

## Setup
- Worktree: `.worktrees/framework-release-automation` on `feature/framework-release-automation`.
- Base: `d12a495ac6dc3f1864251a009f403ce2cd0e2d3e` (synchronized `main`).
- Git Bash scripts blocked twice by Windows access errors; use manual PowerShell bookkeeping and preserve the SDD gates.

## Preflight task/interface scan
| Tasks | Shared file/interface | Producer → consumer | Finding / ruling |
|---|---|---|---|
| 1 & 4 | `release/scripts/plan-release.mjs` | Task 1 creates planner → Task 4 invokes automatic path | Compatible; Task 4 must extend, not duplicate, Task 1 API. |
| 1 & 4 | `.releaserc.json` | Task 1 configures analyzer/plugins → Task 4 adds asset/recovery integration | Potential overlap; preserve only approved plugins and verify final GitHub asset strategy against spec. |
| 2 & 4 | `release/scripts/bootstrap-policy.mjs` | Task 2 validates one-time bootstrap → Task 4 wires it to dispatch | Compatible; workflow must consume pure policy results and enforce before writes. |
| 2 & 4 | `release/scripts/create-source-archive.mjs` | Task 2 builds deterministic archive → Task 4 attests/uploads it | Compatible; tag/SHA identity must remain exact. |
| 3 & 4 | `.github/workflows/ci.yml` | Task 3 consolidates jobs → Task 4 adds publishing behavior | Sequential dependency; Task 4 modifies Task 3 workflow and must retain contexts/permissions/guards. |
| 3 & 4 | release interfaces | Task 3 establishes trigger and job permissions → Task 4 creates bootstrap/auto paths | Compatible only if all required jobs are dependencies and writes remain isolated to release job. |
| 5 & earlier | README/CONTRIBUTING/runbook | Tasks 1–4 determine actual behavior → Task 5 documents it | Task 5 must follow implemented behavior, no unsupported claims. |
| 6 & earlier | GitHub/Packagist/tusk-app | Tasks 1–5 prepare release → Task 6 publishes/verifies | External/security-sensitive writes require stopping for user confirmation at that gate. |

| Task | Internal consistency scan | Result |
|---|---|---|
| 1 | Declared planner interface, required analyzer cases, npm tooling/config | Consistent. |
| 2 | Pure bootstrap guard and deterministic Git-derived archive/checksum | Consistent; workflow integration deferred to Task 4. |
| 3 | Existing status names, branch triggers, least-privilege release job | Consistent; Task 4 owns actual publication behavior. |
| 4 | Bootstrap + semantic-release + recovery + attestations | Potential implementation complexity; preserve stable immutable tag and fail-closed recovery. No spec contradiction identified. |
| 5 | Docs reflect completed implementation and Packagist manual prerequisite | Consistent. |
| 6 | First publication only after PR merge and Packagist readiness | Consistent; stop before external release/registry operation for confirmation. |

## Tasks
- [x] Task 1: Pin release tooling and prove version decisions.
- [x] Task 2: Validate bootstrap policy and build attested source assets.
- [x] Task 3: Consolidate CI and gate releases on all required checks.
- [x] Task 4: Wire initial and automatic publishing, provenance, and recovery.
- [ ] Task 5: Document and exercise the Packagist and operator workflow.
- [ ] Task 6: Publish and verify the first Composer release.

Task 2: complete (commit d12a495..585f4c7, review approved; 10/10 focused tests passed in the controller run).
Task 2: ⚠️ integration verification deferred — release caller must supply live main SHA/tag state; Task 4 owns it.
Ruling: limit version-triggering commits to breaking changes, `feat`, and `fix` — the approved contributing policy names these as release triggers and leaves `perf`/`revert` unspecified; semantic-release defaults silently expand that policy, so configure explicit rules and tests. Cost if wrong: maintainers may expect `perf` or `revert` to publish a patch and will need to use `fix:` or amend the policy later.
Task 1: fix round 1/5 (finding addressed, no new breakage; commit ec74904..2db9486).
Task 1: complete (commits d12a495..2db9486, review clean; controller verification `npm test`: 18/18).
Task 3: complete (commit 8cb4866, review approved; pinned actionlint and structural checks reported green).
Task 3: ⚠️ Task 4 must add Node release-tool tests to required CI, authenticate only release-job tag pushes while PRs remain read-only, and verify candidate tag/SHA plus recovery before publication.
Task 4: complete (controller tests 54/54, composer validate, independent scoped review approved). Local actionlint download was blocked by the machine's invalid proxy at 127.0.0.1:9; structural workflow assertions passed and CI will run pinned actionlint. The first reviewer caught an unsupported `gh api` absolute upload URL and semantic-release asset config field mismatch; both were corrected and re-reviewed. Automatic release notes now flow into GitHub Releases.
Ruling: recovery inspects the latest stable tag/release/assets before semantic-release planning; if release/assets are missing, rebuild from that tag's SHA, validate existing asset digests, and create/upload only missing objects. Bootstrap retry may recover only same `v0.3.2` SHA; conflicting SHA/digest fails. This avoids a partial publication blocking later releases without moving immutable state. Cost if wrong: a malformed latest tag could halt new releases until manually repaired, intentionally fail-closed.
