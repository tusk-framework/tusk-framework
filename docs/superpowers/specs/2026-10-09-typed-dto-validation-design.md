# Typed DTO Validation Design

## Goal

Provide a concise, predictable validation model for typed HTTP request DTOs in Tusk while preserving explicit application validation and the PSR-7 escape hatch.

## Context

`tusk-web` currently hydrates flat constructor DTOs in `ArgumentBinder`. Missing required fields and scalar conversion failures become HTTP 422 errors. `HttpKernel` renders JSON errors as Problem Details, but there is no general field-validation implementation. The `tusk-validation` package currently contains a manifest and README only. Issues #11 and #12 request a clear typed PHP programming model and language-inspired DX; validation is an explicit remaining gap, not a claim already satisfied by the typed DTO contract.

## Chosen approach

Use a hybrid validation model:

1. Common, deterministic input constraints are declared with PHP attributes on DTO constructor parameters (including promoted properties), e.g. `#[NotBlank]`, `#[Email]`, and `#[Length(min: 2, max: 80)]`.
2. Application-specific rules that need services, persistence, or domain knowledge are implemented as explicit validators registered by the application. Framework validation itself performs no I/O and has no hidden request state.
3. `tusk-validation` owns the constraint types, validation result/violation model, built-in rules, and validator extension contract. `tusk-web` integrates validation after successful DTO hydration. Existing scalar binding and PSR request/response behavior remain intact.

This keeps the common path readable without turning attributes into a domain-rule language or adding a second controller API.

## Public behavior

- Validation applies automatically to typed request DTO arguments only. `Request` and `ServerRequestInterface` arguments remain explicit low-level paths and are not implicitly validated.
- The binder first constructs the DTO using the currently documented flat-constructor contract. Missing fields and failed scalar conversions remain binding errors. Once constructed, the configured validator evaluates its declared constraints and registered application validators.
- A valid DTO is passed to the controller unchanged. Multiple violations are collected in one pass and returned as HTTP 422.
- JSON clients receive `application/problem+json` using the existing Problem Details envelope, with an `errors` extension mapping stable DTO field paths to lists of safe violation messages/codes. Do not include submitted values, exception traces, or validator internals. Debug mode must not reveal submitted values.
- Non-JSON clients continue to receive the existing HTML error behavior; this feature does not introduce a separate form-request abstraction.
- DTO constraints are deterministic and side-effect-free. Database uniqueness, authorization, and other service/domain decisions belong in explicit application validators or application services, not built-in attributes.

## Lifecycle and metadata

Constraint metadata is inspected/compiled during application preparation and reused as immutable class metadata across requests. It must not be recomputed as mutable per-request state or stored in a worker-global mutable result. Invalid constraint arguments, unsupported constraint targets, and duplicate/ambiguous metadata fail application preparation with an actionable diagnostic, before serving traffic. Validation results are request-local.

The initial implementation must use Tusk's existing boot/container composition and avoid introducing a separate manifest/code-generation subsystem. If the current boot flow cannot meet the preparation-time requirement cleanly, stop and revise the spec rather than silently moving metadata inspection into request handling.

## Extension contract

- Built-in constraints are small immutable attributes with constructor-validated options and stable violation codes.
- Custom application validators are explicit services registered through the application/container integration. Their contract receives the DTO and returns violations; it must not mutate the DTO or retain request data between calls.
- The validator aggregates built-in and custom violations deterministically. Ordering is stable by DTO field declaration then constraint registration order, so tests and clients do not see nondeterministic error arrays.
- Custom validators may use application services, but the framework does not infer their dependencies from attribute constructors or instantiate arbitrary class names from request data.

## Error contract

The existing Problem Details keys (`type`, `title`, `status`, `instance`, and `request_id`) remain unchanged. A validation response adds an `errors` object whose keys are DTO field paths and whose values are arrays of stable safe messages or codes. The exact content type and status are `application/problem+json` and `422`. No input values or internal exception details are included, even when debug mode is enabled.

Binding failures remain distinguishable from constraint violations by stable problem type/title and an appropriate error code; both remain status 422. Existing clients relying only on status and the established envelope remain compatible.

## Scope boundaries

- No nested DTO hydration, union/enum conversion, validation of arbitrary arrays, automatic database rules, authorization policy, or form-request layer.
- No change to route discovery, controller generation, generic typed configuration, or RoadRunner worker lifecycle.
- No new mandatory third-party dependency.
- Preserve the low-level PSR-7 request/response escape hatch.
- Update issue #11/#12 progress only when an implementation slice is merged; this spec alone does not satisfy their acceptance criteria.

## Verification criteria

- Valid DTO reaches the controller unchanged.
- Missing and invalid scalar values retain the existing binding behavior.
- One and multiple built-in constraint violations yield deterministic 422 Problem Details with field paths and no submitted values.
- Custom validators can be registered and injected through normal application composition; their violations aggregate with built-in rules.
- Invalid constraint metadata fails during application preparation, before request handling.
- A persistent-worker test proves violations from one request do not leak into a later request.
- PSR request/response behavior and non-JSON error behavior remain compatible.
- Documentation clearly distinguishes input validation from domain validation and states unsupported nested/union/enum behavior.
