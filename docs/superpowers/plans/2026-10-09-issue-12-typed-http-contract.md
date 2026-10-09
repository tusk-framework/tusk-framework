# Typed HTTP Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Tusk's existing typed request DTO and response serialization behavior easy to understand, test, and adopt without introducing a competing HTTP API.

**Architecture:** Document and test the existing controller → constructor DTO → JSON result path, tightening only behavior that is demonstrably ambiguous or incorrect. Keep route discovery/boot integration owned by issue #11 and preserve PSR request/response escape hatches.

**Tech Stack:** PHP 8.2+, PHPUnit, existing Tusk `ArgumentBinder`, `HttpKernel`, `Response`, controller attributes.

**Spec:** Approved design in the conversation; issue #12 `adopt-language-inspired-developer-experience-for-tusk-php`; existing `docs/superpowers/plans/2026-10-03-programming-model-dx-v1.md` and current HTTP package docs.

## Global Constraints

- Preserve PSR-7 request/response support and explicit low-level routing.
- No new mandatory dependencies, generic manifest layer, or request-path reflection expansion.
- DTO contract in this phase is flat constructor hydration from the parsed request body, with existing scalar conversion/default behavior. Do not change behavior for unsupported types or JSON encoding failures in this issue unless a focused test demonstrates a current correctness/security defect and the issue scope is updated.
- Do not claim validation support that is not implemented.
- Do not include CRUD generation, generic typed configuration, or declarative resilience metadata.

## Review Focus

- Missing required constructor input: stable client error and no leaked exception internals.
- Optional constructor defaults: defaults remain honored.
- Scalar conversion errors: existing status/problem shape is documented and pinned by tests.
- Object serialization failure: response behavior must be explicit; do not silently emit malformed JSON.
- Controller returns PSR response: pass through without re-encoding or changing headers/body.

---

### Task 1: Pin the supported DTO contract

**Files:**
- Test: `tusk-web/tests/Http/ArgumentBinderTest.php`
- Modify only if needed: `tusk-web/src/Http/ArgumentBinder.php`

**Interfaces:**
- Uses the current binder entry point and exception types; no new public DTO abstraction is introduced.

- [x] Add/confirm tests for a readonly flat DTO with required fields, optional defaults, and supported scalar casts.
- [x] Add tests for missing required fields and invalid scalar conversion, asserting exact status and stable error information.
- [x] Review the binder's current unsupported-parameter behavior and document the observed boundary; do not add a new conversion policy in this task.
- [x] Run focused binder tests. For newly added tests, confirm the expected behaviors match the implementation before deciding whether a code change is warranted.
- [x] If a mismatch is found in the already-supported required/default/scalar cases, make the smallest correction while preserving request precedence and bindings; otherwise keep this task test/documentation-only.
- [x] Run focused binder tests and confirm all pass.

### Task 2: Pin typed result and PSR response behavior

**Files:**
- Test: `tusk-web/tests/HttpKernelTest.php`
- Modify only if needed for an already-promised behavior: `tusk-web/src/HttpKernel.php`, `tusk-web/src/Http/Response.php`

**Interfaces:**
- Consumes current controller result normalization and `ResponseInterface` passthrough.
- Produces no new result wrapper; arrays/objects continue through the current JSON contract.

- [x] Add/confirm tests for DTO/object-to-JSON, array-to-JSON, string-to-HTML, and PSR response passthrough.
- [x] Add/confirm tests for JSON Problem Details redaction outside debug mode and current debug behavior.
- [x] Keep encoding-failure policy unchanged in this scope; record it as a follow-up if current behavior is ambiguous.
- [x] Run focused kernel tests and confirm all pass; make only necessary implementation changes for regressions against the existing contract.

### Task 3: Publish recommended example and boundary documentation

**Files:**
- Modify: `tusk-web/README.md`
- Test: `tusk-web/tests/HttpKernelTest.php` and `tusk-web/tests/Http/ArgumentBinderTest.php` as the executable contract for the documented sample

**Interfaces:**
- Example uses the issue #11 attributed-controller conventions, but this task does not modify controller discovery, `ApplicationBuilder`, project generator, or CLI generator files.

- [x] Add a concise read/create controller example using an immutable request DTO and typed response object; label any service/persistence methods as application-defined, and do not imply generated CRUD support.
- [x] Document exact supported flat DTO construction, scalar conversion, defaults, error behavior, and PSR request/response escape hatches.
- [x] Clearly state that general field validation, nested DTO mapping, enums/unions, and typed universal config are not part of this contract yet.
- [x] Verify the sample's framework-facing signatures against the actual API, run targeted tests, PHPStan/Pint on touched PHP files, and `git diff --check`.

## Execution Boundary

Route discovery, generated controller layout, and boot integration belong to issue #11. This issue consumes the public convention without changing its discovery or generation implementation.
