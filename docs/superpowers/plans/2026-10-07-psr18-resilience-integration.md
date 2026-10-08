# PSR-18 Resilience Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a provider-neutral PSR-18 client decorator that applies Tusk's existing resilience pipeline to outbound HTTP calls while preserving PSR-18 response and request-replay semantics.

**Architecture:** Implement the integration in `tusk-cloud/src/Resilience/Http` using only PSR-7 and PSR-18 interfaces. The decorator maps configured requests to a named existing pipeline, surfaces retryable HTTP statuses to the existing executor through an internal exception, restores the final response when retries exhaust, and only retries requests explicitly eligible for safe replay.

**Tech Stack:** PHP 8.2+, PSR-7, PSR-18, existing Tusk resilience pipeline, PHPUnit 10, PHPStan 2.2, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-06-resilience-core-design.md` (HTTP integration section)

**Issue scope:** This plan delivers only the PSR-18 HTTP slice of [framework issue #29](https://github.com/tusk-framework/tusk-framework/issues/29). It does not complete or close #29. Event/metric emission, typed configuration and boot validation, and health/readiness contributors remain deferred follow-up phases.

## Global Constraints

- Keep the integration in `tusk-cloud`; do not add a package or third-party runtime dependency.
- Keep public contracts provider-neutral and do not import Guzzle, RoadRunner, or OpenTelemetry SDK classes.
- Retry only transient HTTP statuses `408`, `425`, `429`, and `5xx` by default; other `4xx` responses are returned without retry.
- Never retry unsafe methods unless `RetryPolicy::allowUnsafeRetries()` is true and the request supplies a non-empty idempotency key.
- Never replay a request body unless the explicit replay policy permits it and the body stream is seekable; restore its original position before each attempt.
- Preserve PSR-18 semantics: return the last HTTP response after retry exhaustion; transport and policy failures remain exceptions.
- A generic PSR-18 client cannot be forcibly interrupted; the existing retry executor checks deadlines before dispatch and between attempts, while transport timeouts must be configured on the underlying client.
- Do not change the Engine or add runtime/global state.

## Review Focus

- Non-seekable or partially consumed request bodies must not be retried; test that the underlying client is called once and the body is unchanged.
- Unsafe methods must not retry by default; test both missing idempotency key and explicitly authorized idempotent-key cases.
- A retryable final HTTP response must be returned, not leaked as an internal exception; test maximum-attempt exhaustion and original response identity.
- A `4xx` other than configured transient statuses must not retry; test the call count and returned status.
- A transport exception must retain its original throwable and be classified without converting malformed requests into retries; test network and request exception types.

---

### Task 1: Define HTTP failure classification and retryable response carrier

**Files:**

- Create: `tusk-cloud/src/Resilience/Http/RetryableResponseException.php`.
- Create: `tusk-cloud/src/Resilience/Http/HttpFailureClassifier.php`.
- Test: `tusk-cloud/tests/Resilience/Http/HttpFailureClassifierTest.php`.

**Interfaces:**

- `RetryableResponseException::__construct(ResponseInterface $response)` and `response(): ResponseInterface`.
- `HttpFailureClassifier::classify(Throwable $failure, OperationContext $context): FailureDecision`.
- The classifier accepts a configurable inner `FailureClassifierInterface`, recognizes retryable response statuses and PSR-18 `NetworkExceptionInterface`, delegates other failures, and treats `RequestExceptionInterface` as terminal unless it is also a network exception.

- [x] **Step 1: Write failing tests** for transient statuses 408/425/429/5xx, non-transient 4xx, network exceptions, ordinary request exceptions, and delegation of unknown failures.
- [x] **Step 2: Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/Http/HttpFailureClassifierTest.php`** and confirm the tests fail because the HTTP types do not exist.
- [x] **Step 3: Implement the response carrier and classifier** with no provider-specific dependencies.
- [x] **Step 4: Run the focused test** and confirm all cases pass.
- [x] **Step 5: Run Pint and PHPStan** on the new HTTP resilience namespace.

### Task 2: Implement the PSR-18 resilient client and replay guardrails

**Files:**

- Create: `tusk-cloud/src/Resilience/Http/Psr18ResilientClient.php`.
- Create: `tusk-cloud/src/Resilience/Http/RequestReplayPolicy.php`.
- Modify: `tusk-cloud/src/Resilience/Http/HttpFailureClassifier.php` to expose `public static function isRetryableStatus(int $status): bool`, shared by response handling and classification.
- Test: `tusk-cloud/tests/Resilience/Http/Psr18ResilientClientTest.php`.

**Interfaces:**

- `Psr18ResilientClient implements Psr\Http\Client\ClientInterface`.
- Constructor signature: `__construct(ClientInterface $client, ResiliencePipelineFactory $pipelineFactory, string $operation, RetryPolicy $retryPolicy, RequestReplayPolicy $replayPolicy, ?CircuitBreakerPolicy $circuitBreakerPolicy = null, ?BulkheadPolicy $bulkheadPolicy = null, ?RateLimitPolicy $rateLimitPolicy = null, ?callable $fallback = null, ?callable $contextFactory = null)`.
- Fallback callable signature is `fn (Throwable $failure, OperationContext $context): mixed`; its result must be a `ResponseInterface`, otherwise `sendRequest()` throws `UnexpectedValueException`.
- Context factory signature is `fn (RequestInterface $request): OperationContext`; when omitted, the client creates a context for the configured operation name.
- `RequestReplayPolicy::create(string $idempotencyKeyHeader = 'Idempotency-Key', array $idempotentMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'], bool $allowBodyReplay = false): self` validates a non-blank header name and non-blank method values. It exposes `isIdempotentMethod(string $method): bool`, `idempotencyKeyHeader(): string`, and `allowsBodyReplay(): bool`. Method matching is case-sensitive.
- A body with known size zero may be retried without body-replay opt-in; a non-empty or unknown-size body requires `allowBodyReplay: true`. In all cases, the request stream must be seekable and its starting position readable before any retry is allowed.
- An unsafe request is authorized only when exactly one non-empty idempotency-key header value is present; duplicate values are rejected as ambiguous.
- `RequestReplayPolicy` does not independently authorize unsafe retries.
- Construct and retain one configured `ResiliencePipeline` per decorator instance, applying retry, circuit-breaker, bulkhead, rate-limit, and fallback policies once; this preserves bulkhead/rate-limiter state across calls in a persistent worker.
- Normalize the pipeline's retry policy to `allowUnsafeRetries: false`; for each request derive an immutable context preserving the factory-provided deadline/cancellation/metadata. Request eligibility requires an idempotent method or (for unsafe methods) the configured retry policy allowing unsafe retries and exactly one non-empty idempotency key, plus a readable stream position and either an empty body or explicit body-replay permission. When a context factory is supplied, its `retryAllowed: false` is an additional caller veto; without a context factory, the decorator derives retry permission from request eligibility alone. This prevents the generic unsafe-retry bypass from overriding HTTP replay guardrails while keeping the default API useful for safe methods.
- `sendRequest(RequestInterface $request): ResponseInterface` executes the request in that retained pipeline. Retryable statuses are surfaced as `RetryableResponseException`; after retries exhaust, return its original response. Other 4xx responses are returned normally.
- If the request body is not replayable or its starting position cannot be read (including a known-empty but non-seekable stream), allow the first dispatch but set `retryAllowed` false. Before each replay, seek the body stream to the position captured before the first dispatch. Do not seek or mutate it for a single-attempt request.

- [x] **Step 1: Write failing tests** for safe-method transient response retries, final-response unwrapping on exhaustion, no retry for ordinary 4xx, retryable network errors, unsafe-method default denial, explicit idempotency-key authorization, non-seekable and unreadable-position body denial, caller retry veto, body-position restoration, propagation of terminal transport exceptions, expired-deadline and cancellation rejection before dispatch, circuit-breaker rejection/accounting, bounded bulkhead admission/release, shared rate-limit state across calls, and explicit response fallback.
- [x] **Step 2: Run `vendor/bin/phpunit tusk-cloud/tests/Resilience/Http/Psr18ResilientClientTest.php`** and confirm the tests fail because the decorator does not exist.
- [x] **Step 3: Implement `RequestReplayPolicy`** with the constructor and accessors above; add tests for the default method allowlist, case-sensitive matching, non-blank header/method validation, body replay disabled by default, and explicit body-replay enablement.
- [x] **Step 4: Implement `Psr18ResilientClient`** with the constructor above; build the configured pipeline exactly once in the constructor, normalize its retry policy to `allowUnsafeRetries: false`, derive per-request context, restore body position before replay, and return the last response if the response carrier is the exhausted failure.
- [x] **Step 5: Run the focused client test** and confirm all cases pass.
- [x] **Step 6: Run the full resilience test suite** and confirm existing pipeline behavior is unchanged.

### Task 3: Document configuration and integrate quality gates

**Files:**

- Modify: `tusk-cloud/README.md` with a minimal PSR-18 decorator example, safe retry defaults, and the limitation that transport-level timeouts belong to the underlying client.
- Modify: `tusk-cloud/tests/Resilience/ResilienceArchitectureTest.php` only if architecture assertions need to cover HTTP integration boundaries.

**Interfaces:**

- The decorator uses the operation context and existing pipeline deadline checks; it must not create its own clock, global state, or transport-specific timeout options.
- Transport timeouts remain configured on the injected PSR-18 client because PSR-18 has no standard per-request timeout API.

- [x] **Step 1: Document the public API and operational limits** in `tusk-cloud/README.md`, including explicit examples for retry/circuit/bulkhead/rate-limit composition and the fact that an in-flight PSR-18 call requires transport-level timeout configuration.
- [ ] **Step 2: Run verification:** `vendor/bin/phpunit tusk-cloud/tests/Resilience`, `vendor/bin/phpstan analyse tusk-cloud/src tusk-contracts/src --no-progress`, `vendor/bin/pint --test` for touched PHP files, `composer validate --no-check-publish`, and `git diff --check`.
- [ ] **Step 3: Review the final diff** for safe retry semantics, response identity, body position, bounded policy behavior, and provider neutrality; open the PR using the repository's required PR structure and request a fresh code review.

## Self-Review

- **Spec coverage:** Covers PSR-18 decoration, transient response/transport classification, retry/circuit/bulkhead/rate-limit/fallback composition, request-body replay rules, unsafe-method idempotency guardrails, operation context/deadline/cancellation admission, provider-neutral dependencies, and user-facing operational documentation. Event/metric sinks, typed configuration/boot validation, health/readiness, and trace-header propagation are explicitly deferred to later phases of issue #29.
- **Step scan:** The behavior-changing tasks start with focused failing tests; the documentation/quality task ends with verifiable repository checks.
- **Type consistency:** The decorator gets the factory's builder by operation name, configures one retained pipeline with a normalized `RetryPolicy`, optional circuit/bulkhead/rate-limit policies and response fallback, and runs each request with an immutable `OperationContext`; the classifier recognizes the response carrier and PSR-18 network failures before delegating.
- **Review focus:** Each of the five listed failure modes and expired deadlines has an explicit test in Task 1 or Task 2.
- **Proportion:** Three tasks cover classification, transport integration, and docs/verification; no runtime, configuration compiler, tracing backend, or Engine work is included.
