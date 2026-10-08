# Resilience Configuration and Lifecycle Design

## Intent

Complete the production configuration path for Tusk's existing provider-neutral resilience primitives. The programmatic pipeline, PSR-18 integration, events, metrics, and health-check registry already exist; this design must compose them rather than reimplement them.

## Outcomes

1. Applications can define named resilience policies with typed, immutable configuration and environment/profile overrides.
2. Configuration is validated before serving traffic and diagnostics identify the invalid policy/key and expected constraint.
3. Named policies resolve deterministically to the existing `ResiliencePipelineFactory` and builder primitives.
4. Readiness may reflect local boot/configuration validity; liveness never depends on remote services.
5. Persistent RoadRunner workers do not retain per-request resilience context or instrumentation state across requests.

## Non-goals

- Reimplement retry, deadlines, cancellation, circuit breaker, bulkhead, rate limiter, fallback, PSR-18 replay safety, or telemetry.
- Runtime-reflection attributes or compiled declarative metadata, which remain tracked in issues #11/#12.
- Proxy/gateway behavior, changes to the RoadRunner request path, mandatory telemetry providers, or Engine diagnostics.
- Automatically retrying unsafe methods, replaying non-rewindable request bodies, or silently applying fallback behavior.

## Recommended approach

Use explicit typed configuration objects and a small binder/validator that turns application configuration into immutable named policy definitions. A resolver maps each definition to the existing pipeline builder. Keep direct programmatic construction available as an escape hatch. Avoid framework-wide reflection, implicit naming conventions, and a new generic configuration DSL.

Configuration precedence is deterministic: base configuration, then the active environment/profile override, then explicit application overrides. Unknown fields and invalid values fail early; secrets are not part of resilience policy configuration and must never be included in diagnostics.

## Delivery boundary

### Phase 1 — Configuration contract

- Define typed immutable policy configuration for currently supported policies.
- Bind named operation/service policies and environment/profile overrides.
- Validate bounds, names, unsupported combinations, and retry safety constraints.
- Resolve named policies to existing pipeline primitives without changing their semantics.
- Add deterministic unit tests for binding, precedence, validation, and resolution.

### Phase 2 — Framework lifecycle integration

- Integrate configuration validation into application boot and `tusk config:validate`; the CLI path is side-effect-free and performs no network calls.
- Surface local configuration validity in readiness; liveness does not call external dependencies.
- Verify worker/request-scope isolation and instrumentation behavior across persistent requests.
- Add CLI, boot, readiness, worker-isolation tests and operator documentation.

## Error behavior

Invalid configuration fails fast with a stable, actionable diagnostic naming the policy and configuration path, without exposing secret values. Runtime operation failures continue to preserve the original exception/result according to the existing resilience contracts. Optional event/metric sink failures remain isolated.

## Compatibility

- Preserve the existing programmatic APIs and PSR contracts.
- Do not change default retry safety or request-body replay rules.
- Avoid new mandatory dependencies.
- Keep existing health endpoints/contracts compatible; readiness additions are additive.

## Verification

- Unit tests for policy binding, profile precedence, validation boundaries, and resolver output.
- CLI tests proving validation is deterministic, side-effect-free, and actionable.
- Boot/readiness tests proving local-only readiness and external-independent liveness.
- Persistent-worker tests proving no request-scoped state leaks between operations.
- Full CI matrix for PHP 8.2/8.3/8.4, static analysis, Pint, and Composer validation.

## Open implementation detail

The exact PHP namespace and serialized configuration shape should follow the repository's existing configuration conventions discovered during implementation; this must not change the semantics or scope above.
