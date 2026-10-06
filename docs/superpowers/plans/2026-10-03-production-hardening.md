# Tusk Framework Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar o framework PHP seguro e previsível para aplicações de longa duração sem quebrar as interfaces públicas existentes.

**Architecture:** Preservar PSR-7 e o adapter RoadRunner. Corrigir o fluxo de contexto no `HttpKernel`, centralizar a política de erro, tornar guards request-safe e fazer a fila usar claim atômico. O worker RoadRunner é o único contrato de transporte.

**Tech Stack:** PHP 8.2+, PHPUnit 10, PHPStan 2, PSR-7, Doctrine DBAL, firebase/php-jwt.

**Spec:** `docs/superpowers/specs/2026-10-03-production-hardening-design.md`

## Global Constraints

- `JWT_SECRET` deve ter pelo menos 32 bytes; ausência ou valor curto falha fechado.
- `APP_DEBUG` aceita apenas `1`, `true`, `yes` ou `on` como habilitado; default é `false`.
- O contrato PSR-7 continua usando requests e responses HTTP completos.
- Não reescrever o container nem trocar PSR-7 nesta entrega.
- Cliente cloud HTTP permanece fora do escopo e será tratado em issue própria.

## Review Focus

- Uma rota protegida sem `_controller`/`_action` deve ser bloqueada, não executada anonimamente — Task 2.
- Uma requisição com token somente na query string não deve autenticar por padrão — Task 1.
- Uma exceção com `Accept: application/json` não deve revelar arquivo, linha ou mensagem quando debug está desligado — Task 2.
- Dois consumidores não podem obter o mesmo job, e jobs abandonados devem voltar após 300 segundos — Task 4.
- Um upload PSR-7 deve produzir `UploadedFileInterface` e ser limpo ao final da requisição — Task 3.

### Task 1: Request accessors and fail-closed authentication

**Files:**
- Modify: `tusk-web/src/Http/Request.php`
- Modify: `tusk-security/src/Authentication/JwtGuard.php`
- Modify: `tusk-security/src/Authentication/TokenGuard.php`
- Test: `tusk-web/tests/Http/RequestTest.php`
- Create: `tusk-security/tests/Authentication/JwtGuardTest.php`
- Create: `tusk-security/tests/Authentication/TokenGuardTest.php`

**Interfaces:**
- `Request::header(string $name, mixed $default = null): mixed` reads PSR-7 headers case-insensitively.
- `Request::get(string $key, mixed $default = null): mixed` reads parsed body first, then query parameters.
- Guards accept an optional final `bool $allowQueryToken = false`, preserving existing positional arguments.

- [ ] **Step 1: Write failing tests** for case-insensitive headers, body/query lookup, missing/short JWT secret rejection, Bearer authentication, and disabled query tokens.
- [ ] **Step 2: Run the focused PHPUnit tests** with `vendor/bin/phpunit ...`; confirm failures are caused by missing accessors/policy, not test setup.
- [ ] **Step 3: Implement the accessors and guard policy**; remove the insecure fallback secret and make invalid JWT configuration return no authenticated user without exposing the secret.
- [ ] **Step 4: Run the focused tests** and confirm all pass.
- [ ] **Step 5: Commit** `fix: harden request authentication guards`.

### Task 2: Route security context and safe error responses

**Files:**
- Create: `tusk-web/src/Http/HttpException.php`
- Modify: `tusk-web/src/HttpKernel.php`
- Modify: `tusk-security/src/Middleware/SecurityMiddleware.php`
- Test: `tests/Integration/WebPipelineIntegrationTest.php`
- Create: `tusk-web/tests/HttpKernelTest.php`
- Create: `tusk-security/tests/Middleware/SecurityMiddlewareTest.php`

**Interfaces:**
- `HttpException` carries an HTTP status and a public-safe message.
- `HttpKernel` adds `_controller` and `_action` request attributes before middleware execution.
- Production errors return generic HTML/JSON plus `X-Request-Id`; debug details are controlled only by `APP_DEBUG`.

- [ ] **Step 1: Write failing tests** asserting route attributes reach middleware, authentication failures return 401, authorization failures return 403, and JSON errors omit file/line/message when debug is false.
- [ ] **Step 2: Run the focused tests** and verify the current 500 responses and missing request attributes fail as expected.
- [ ] **Step 3: Implement `HttpException`, route attribute propagation, request ID generation, and debug-gated error rendering.** Keep full exception details in `error_log` only.
- [ ] **Step 4: Run focused tests and the existing web integration suite.** Confirm both normal and not-found routes remain unchanged.
- [ ] **Step 5: Commit** `fix: enforce route security and redact errors`.

### Task 3: RoadRunner request conversion and request-scope cleanup

**Files:**
- Modify: `tusk-runtime/src/Adapters/RoadRunnerAdapter.php` if shared cleanup behavior is needed
- Modify: `tusk-runtime/tests/Adapters/RoadRunnerAdapterTest.php`

**Interfaces:**
- `RoadRunnerAdapter` receives PSR-7 requests and returns PSR-7 responses through the worker channel.

- [ ] **Step 1: Write failing tests** for headers, cookies, query, parsed body, single/multiple uploads, invalid upload metadata, and response conversion.
- [ ] **Step 2: Run the focused tests** and confirm uploads are currently absent or ignored.
- [ ] **Step 3: Keep request scope reset in one `finally` block per request through the shared lifecycle manager.
- [ ] **Step 4: Run focused tests and runtime tests**; confirm malformed input produces a controlled 400/500 response without terminating the worker loop.
- [ ] **Step 5: Commit** `feat: preserve uploads in RoadRunner requests`.

### Task 4: Atomic database queue claims and recovery

**Files:**
- Modify: `tusk-events/src/Queue/DatabaseQueue.php`
- Create: `tusk-data/tests/Integration/DatabaseQueueTest.php`
- Create: `tusk-events/tests/Queue/DatabaseQueueTest.php`

**Interfaces:**
- `DatabaseQueue::__construct(Connection $connection, int $reservationTimeoutSeconds = 300)` preserves the existing one-argument call.
- `pop(): ?array` atomically claims one pending or expired processing job.

- [ ] **Step 1: Write failing tests** using two DBAL connections for duplicate-claim prevention, plus a stale processing job that becomes claimable after the configured timeout.
- [ ] **Step 2: Run the focused tests** and observe duplicate claims or lack of recovery in the current implementation.
- [ ] **Step 3: Implement compare-and-update claiming**: select a candidate, update only if its status/timestamp still match, retry on zero affected rows, and avoid database-specific `FOR UPDATE` syntax.
- [ ] **Step 4: Run queue tests and the existing Doctrine integration tests**; confirm all jobs remain idempotently claimable.
- [ ] **Step 5: Commit** `fix: make database queue claims atomic`.

### Task 5: Full verification and issue handoff

**Files:**
- Modify: `README.md` only where runtime/security behavior changed

- [ ] **Step 1: Run** `vendor/bin/phpunit` and record the complete result.
- [ ] **Step 2: Run** `vendor/bin/phpstan analyse` and record the complete result.
- [ ] **Step 3: Review the diff and verify no public PSR-7 API field was removed.
- [ ] **Step 4: Create public GitHub issues only for intentionally deferred features, each with reproduction/context, scope, and acceptance criteria.
- [ ] **Step 5: Commit** `docs: document production hardening status` if README changed; otherwise record the clean verification without creating an empty commit.
