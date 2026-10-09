# Tusk Validation

`tusk/validation` provides deterministic validation for typed PHP DTOs. It is
independent of HTTP and has no mandatory third-party dependencies.

## Built-in constraints

Use PHP attributes on constructor parameters (including promoted properties):

```php
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;

final readonly class RegisterUser
{
    public function __construct(
        #[NotBlank]
        #[Length(min: 2, max: 80)]
        public string $name,
        #[Email]
        public ?string $email = null,
    ) {}
}
```

`NotBlank` rejects `null` and whitespace-only strings. `Email` accepts `null`
and an empty string, otherwise checking the value with PHP's email filter.
`Length` accepts `null`, counts UTF-8 code points, and supports optional
non-negative `min` and `max` limits. These constraints target string and
nullable-string constructor parameters. Invalid constraint metadata is reported
when the application is prepared, before it starts serving requests.

The package exposes immutable validation results and violations. Violations
contain a field path, stable machine-readable code, and safe message; they never
retain or expose submitted values. Validation metadata is compiled at boot and
can be held in a sealed registry for request-time use.

## Application-owned rules

Rules that depend on domain policy or persistence belong in explicit custom
validators, not in generic database-aware constraints. In a Tusk application,
implement `CustomValidatorInterface` and register the validator for its DTO with
`ApplicationBuilder::withValidator(RegisterUser::class, RegisterUserValidator::class)`.
The framework resolves the validator through the application container when
validation runs, preserving the service's configured container scope and
constructor-injected dependencies. Request/prototype validators are
instantiated on first use, so DI errors for those scopes surface when
validation runs. Custom validators receive the hydrated DTO; built-in
constraints validate the converted constructor input values.

This package does not provide form requests, nested DTO hydration, enum/union
conversion, arbitrary payload validation, or automatic database checks. HTTP
status codes and response formatting are the responsibility of `tusk-web`.

## Installation

```bash
composer require tusk/validation
```
