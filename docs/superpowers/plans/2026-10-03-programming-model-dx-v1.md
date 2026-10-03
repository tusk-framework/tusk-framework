# Tusk Programming Model DX v1

## Goal

Make the recommended Tusk application code concise and typed while keeping PSR APIs available as an advanced escape hatch.

## Scope

1. Add class-level `Controller` and HTTP verb attributes (`Get`, `Post`, `Put`, `Patch`, `Delete`).
2. Preserve the existing generic `Route` attribute for compatibility.
3. Compile class prefixes and verb routes into the existing route manifest.
4. Add typed controller argument binding for route parameters, `Request`, `ServerRequestInterface`, and constructor-based request DTOs.
5. Normalize arrays/DTOs into JSON responses and exceptions into RFC 9457-style Problem Details.
6. Update the controller generator and examples to show the recommended path.

## Design constraints

- constructor injection remains the default;
- no runtime reflection on the request path beyond the first implementation boundary;
- generated routes remain deterministic;
- request-scoped state is reset by the existing RoadRunner adapter;
- low-level PSR handlers remain supported;
- no generic Lombok-like runtime magic.

## TDD tasks

### Task 1 — Attributes and route compilation

- Add failing tests for controller prefixes and verb attributes.
- Implement attributes and compiler support.
- Keep generic `Route` behavior unchanged.

### Task 2 — Typed argument binding

- Add failing tests for route scalar conversion, request injection, and DTO body construction.
- Implement a focused binder with clear errors for unsupported parameters.

### Task 3 — Response and error contract

- Add failing tests for typed JSON responses and problem details.
- Implement response normalization without exposing exception details outside debug mode.

### Task 4 — Generator and integration example

- Update the controller stub and add an integration test using the new syntax.
- Document the recommended programming model.

## Verification

- PHPUnit targeted tests for router, binder, kernel, and integration flow.
- PHPStan/Pint where the local PHP toolchain permits.
- `git diff --check` always.
