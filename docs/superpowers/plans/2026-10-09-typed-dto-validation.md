# Typed DTO Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add deterministic attribute-based validation for typed HTTP DTOs, explicit custom validators, and safe RFC 9457 field errors.

**Architecture:** Implement immutable constraints, violations, validation results, metadata, and validator contracts in `tusk-validation`. Compile DTO validation metadata during application preparation for routed controller actions, register custom validator services through the existing builder/container flow, then inject the validator into `ArgumentBinder` and render aggregated violations through the existing kernel Problem Details path.

**Tech Stack:** PHP 8.2+, PHPUnit 10, existing Tusk container, router, HTTP binder/kernel, Composer packages.

**Spec:** `docs/superpowers/specs/2026-10-09-typed-dto-validation-design.md`

## Global Constraints

- PHP requirement remains `^8.2`.
- No new mandatory third-party dependency.
- No nested DTO hydration, union/enum conversion, arbitrary-array validation, database rules, authorization policy, or form-request layer.
- Preserve PSR-7 request/response escape hatches and the existing flat DTO binding behavior.
- Constraint metadata is compiled during application preparation; invalid metadata fails before traffic is served.
- Validation results and submitted values are request-local and never retained by singleton/worker services.

## Review Focus

- Malformed constraint arguments or unsupported attribute targets fail during application preparation with an actionable error.
- Multiple rules failing for the same field produce stable ordered errors without submitted values.
- Validation results from a request do not leak into a later request handled by a persistent worker.
- DTOs used by explicit routes as well as discovered controllers receive the same preparation-time metadata checks.
- Debug mode never adds submitted values or validator internals to validation Problem Details.

---

### Task 1: Build the validation package core

**Files:**
- Create: `tusk-validation/src/Constraint/NotBlank.php`
- Create: `tusk-validation/src/Constraint/Email.php`
- Create: `tusk-validation/src/Constraint/Length.php`
- Create: `tusk-validation/src/Violation.php`
- Create: `tusk-validation/src/ValidationResult.php`
- Create: `tusk-validation/src/ValidatorInterface.php`
- Create: `tusk-validation/src/CustomValidatorInterface.php`
- Create: `tusk-validation/src/ConstraintValidator.php`
- Create: `tusk-validation/src/Metadata/ValidationMetadata.php`
- Create: `tusk-validation/src/Metadata/ValidationMetadataCompiler.php`
- Create: `tusk-validation/src/Validator.php`
- Test: `tusk-validation/tests/Constraint/ConstraintTest.php`
- Test: `tusk-validation/tests/Metadata/ValidationMetadataCompilerTest.php`
- Test: `tusk-validation/tests/ValidatorTest.php`
- Modify: root `phpunit.xml` to include the validation test suite.

**Interfaces:**
- `ValidatorInterface::validate(object $value, ValidationMetadata $metadata, array $constructorValues, iterable $customValidators = []): ValidationResult`; the constructor-value map is passed for this call only and is never retained.
- `ConstraintValidator::validate(ValidationMetadata $metadata, array $constructorValues): list<Violation>` evaluates built-in rules from the converted constructor arguments rather than reading public DTO properties.
- `CustomValidatorInterface::validate(object $value): iterable<Violation>`.
- `ValidationResult::isValid(): bool` and `violations(): list<Violation>`.
- `Violation` exposes immutable `field(): string`, `code(): string`, and `message(): string`; it contains no submitted value.
- `ValidationMetadataCompiler::compile(string $dtoClass): ValidationMetadata` validates constructor-parameter attributes and produces immutable ordered metadata. Only constructor parameters (including promoted-property parameters) are constraint targets in this phase.
- Built-in constraints have constructor-validated options. `NotBlank` rejects null/whitespace-only strings; `Email` permits null/empty strings and otherwise uses `FILTER_VALIDATE_EMAIL`; `Length` permits null, counts UTF-8 code points, and accepts nullable `min`/`max` with non-negative bounds and `min <= max`. Metadata compilation rejects applying these constraints to non-string/non-nullable-string constructor parameters.
- Stable built-in violation codes are `not_blank`, `email`, `length.min`, `length.max`, and `length.invalid_encoding`.
- `ConstraintValidator` evaluates compiled built-in constraints and aggregates violations in field declaration then attribute declaration order.

- [ ] **Step 1: Write failing constraint and metadata tests** for valid/invalid options, allowed targets, stable codes, and declaration ordering.
- [ ] **Step 2: Run focused tests and confirm they fail** because validation classes do not exist.
- [ ] **Step 3: Implement immutable constraint, violation/result, metadata compiler, and built-in validator types** with no I/O and no dependency on `tusk-web`.
- [ ] **Step 4: Add validator tests** for `NotBlank`, `Email`, `Length`, multiple failures on one field, multiple fields, valid DTOs, and deterministic ordering.
- [ ] **Step 5: Run validation-package tests** and verify all tests pass.

### Task 2: Register application validators and compile route DTO metadata at preparation

**Files:**
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php`
- Modify: `tusk-web/src/Router/Router.php`
- Keep: `tusk-web/src/Router/RouterInterface.php` unchanged; route enumeration is an application-preparation concern on the concrete `Router`.
- Create: `tusk-validation/src/Metadata/ValidationMetadataRegistry.php`
- Create: `tusk-validation/src/CustomValidatorRegistry.php`
- Test: `tusk-core/tests/Foundation/ApplicationBuilderTest.php`
- Test: `tusk-web/tests/Router/ControllerRouteTest.php`.
- Test: `tusk-validation/tests/CustomValidatorRegistryTest.php`

**Interfaces:**
- `ApplicationBuilder::withValidator(string $dtoClass, string $validatorClass): self` registers a custom validator for exactly one DTO class.
- Concrete `Router::controllerActions(): list<array{controller: class-string, method: non-empty-string}>` exposes a stable, de-duplicated list of class/method handlers for preparation-time inspection; callable closures remain outside typed DTO auto-binding. Do not add a method to `RouterInterface`, which would break custom router implementations.
- `ValidationMetadataRegistry::register(string $dtoClass, ValidationMetadata $metadata): void`, `metadataFor(string $dtoClass): ValidationMetadata`, and `seal(): void` provide a boot-populated, immutable-at-request-time metadata registry.
- `CustomValidatorRegistry::for(string $dtoClass): list<CustomValidatorInterface>` returns only validators explicitly associated with that DTO.
- Application preparation compiles metadata for every routed class-typed controller parameter that is not the Tusk request wrapper, a PSR server request, or a container-bound service; this includes explicit controller routes and discovered attributed controllers. Invalid metadata aborts `create()` before returning an application.
- Registered custom validators are resolved from the application container during preparation and aggregated with built-in validation; arbitrary class names from request data are never resolved.

- [ ] **Step 1: Add failing router tests** proving explicit array/class-method handlers and discovered handlers can be enumerated without changing route matching.
- [ ] **Step 2: Add failing builder tests** proving custom validator registration and invalid DTO metadata fail during `create()` before an HTTP request is handled.
- [ ] **Step 3: Implement route-handler enumeration and preparation-time metadata compilation** while keeping closure routes supported as explicit low-level handlers.
- [ ] **Step 4: Implement custom-validator registration and container resolution** using constructor injection; reject invalid/non-validator registrations with actionable boot errors.
- [ ] **Step 5: Test duplicate registration, invalid validator classes, ordering, and a validator depending on a container service.**
- [ ] **Step 6: Run focused router, builder, and validation registry tests** and verify all pass.

### Task 3: Integrate DTO validation and Problem Details

**Files:**
- Modify: `tusk-web/composer.json` to require `tusk/validation`.
- Modify: `tusk-web/src/Http/ArgumentBinder.php`
- Create: `tusk-web/src/Http/ValidationException.php` for typed validation failures.
- Modify: `tusk-web/src/HttpKernel.php`
- Modify: `tusk-core/src/Foundation/ApplicationBuilder.php` to inject the prepared validator and metadata.
- Test: `tusk-web/tests/Http/ArgumentBinderTest.php`
- Test: `tusk-web/tests/HttpKernelTest.php`
- Test: `tusk-core/tests/Foundation/ApplicationBuilderTest.php`

**Interfaces:**
- `ArgumentBinder` receives a `ValidatorInterface`, sealed `ValidationMetadataRegistry`, and `CustomValidatorRegistry`; it passes the hydrated DTO plus a transient map of converted/defaulted constructor argument values. The map is kept only for the current binding call.
- Constructor hydration and scalar conversion remain unchanged; after constructing a typed DTO, the binder validates it and throws a typed validation failure containing only safe violations.
- `HttpKernel` keeps `type`, `title`, `status`, `instance`, and `request_id`; validation adds `type: "urn:tusk:problem:validation"`, `title: "Validation Failed"`, `errors: array<string, list<array{code: string, message: string}>>`, and status 422.
- Debug mode must not expose values, stack traces, or internal validator exceptions for validation failures. Non-validation exception debug behavior remains unchanged.
- `Request` and `ServerRequestInterface` parameters bypass automatic DTO validation.

- [ ] **Step 1: Write failing binder tests** for successful DTO validation, one/multiple constraint violations, aggregation with custom validators, and unchanged missing-field/scalar-conversion behavior.
- [ ] **Step 2: Run focused binder tests and confirm validation cases fail** before implementation.
- [ ] **Step 3: Wire the prepared validator into the binder** and validate only after successful DTO hydration.
- [ ] **Step 4: Write failing kernel tests** for exact 422 Problem Details shape, `application/problem+json`, safe messages/codes, and debug redaction.
- [ ] **Step 5: Implement typed validation error rendering** without changing ordinary binding errors or non-validation exception handling.
- [ ] **Step 6: Add a persistent-worker regression test** sending invalid then valid requests and asserting the second response contains no violations or submitted data from the first request.
- [ ] **Step 7: Run binder, kernel, and application preparation tests** and verify all pass.

### Task 4: Document the supported validation model and verify the whole framework

**Files:**
- Modify: `tusk-validation/README.md`
- Modify: `tusk-web/README.md`
- Modify: root `composer.json` only if the validation package test/autoload setup requires a root manifest adjustment.
- Test: examples in `tusk-validation/tests` and `tusk-web/tests` remain the executable documentation contract.

**Interfaces:**
- Documentation covers the built-in attributes, constructor DTO example, explicit custom validator registration, 422 Problem Details shape, domain-validation boundary, worker safety, and PSR escape hatch.
- Explicitly state unsupported nested DTO, enum/union conversion, arbitrary payload validation, and automatic database checks.

- [ ] **Step 1: Document a minimal immutable DTO example** with built-in constraints and the exact field-error response contract.
- [ ] **Step 2: Document a custom validator registration example** showing service injection while keeping persistence/domain policy application-owned.
- [ ] **Step 3: Validate Composer manifests** with `composer validate --strict`.
- [ ] **Step 4: Run the full suite** with `vendor/bin/phpunit --testdox`.
- [ ] **Step 5: Run PHPStan, Pint on touched PHP files, and `git diff --check`**; report pre-existing findings separately and require no new findings.
- [ ] **Step 6: Review issue #11/#12 acceptance criteria** and update their progress only after the implementation PR is merged; do not mark either issue complete based on this feature alone.
