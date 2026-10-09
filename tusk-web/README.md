# Tusk Web

The **Tusk Web** package provides the HTTP abstractions, routing, and controller logic for the Tusk Framework.

## Features
- **Routing**: High-performance attribute-based and functional routing.
- **Convention-first controllers**: Use `#[Controller]`, `#[Get]`, `#[Post]`, and typed request DTOs.
- **Controllers**: Clean, testable controller architecture.
- **Middleware**: PSR-15 compliant middleware support.

## Recommended controller style

```php
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Get;
use Tusk\Web\Attribute\Post;

#[Controller('/users')]
final class UserController
{
    public function __construct(private UserService $users) {}

    #[Get('/{id}')]
    public function show(int $id): UserView
    {
        return $this->users->find($id); // UserService is application-defined.
    }

    #[Post]
    public function create(CreateUserRequest $request): UserView
    {
        return $this->users->create($request); // Persistence and service behavior are application-defined.
    }
}

final readonly class CreateUserRequest
{
    public function __construct(
        public string $name,
        public string $role = 'member',
    ) {}
}

final readonly class UserView
{
    public function __construct(public int $id, public string $name) {}
}
```

### Request binding and validation contract

A request DTO is an immutable class with a flat constructor. Tusk matches keys from the parsed request body to constructor parameter names; decoding raw JSON is the HTTP server or middleware's responsibility. It converts `int`, `float`, and `bool` with PHP's `FILTER_VALIDATE_INT`, `FILTER_VALIDATE_FLOAT`, and `FILTER_VALIDATE_BOOLEAN` rules; `string` accepts scalar input and casts it to a string; `array` requires an array and passes it through. Missing required fields and failed conversions produce HTTP 422 errors. If a body key is absent, the constructor's declared default is used; an explicit `null` is still a supplied value and must satisfy the parameter type. This is construction, not general field validation.

Controller arrays and objects (including DTOs such as `UserView`) are JSON encoded with `Content-Type: application/json`. A JSON encoding failure becomes a 500 response through the normal error handler; malformed JSON is not returned. Strings are returned as HTML with `Content-Type: text/html`. Returning a PSR-7 `ResponseInterface` passes that response through with its status, headers, and body intact. Controller arguments can also use Tusk's `Request` wrapper or `ServerRequestInterface` when direct PSR-7 access is needed.

Add `tusk/validation` constraints to DTO constructor parameters to validate
successfully converted values:

```php
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\NotBlank;

final readonly class CreateUserRequest
{
    public function __construct(
        #[NotBlank]
        public string $name,
        #[Email]
        public ?string $email = null,
    ) {}
}
```

Built-in rules run after DTO construction. Missing required fields and failed
scalar conversions remain binding errors and are not replaced by constraint
violations. Constraint failures return HTTP 422 with
`Content-Type: application/problem+json` and RFC 9457 Problem Details:

```json
{
  "type": "urn:tusk:problem:validation",
  "title": "Validation Failed",
  "status": 422,
  "instance": "/users",
  "request_id": "…",
  "errors": {
    "name": [{"code": "not_blank", "message": "This value should not be blank."}]
  }
}
```

`errors` maps each constructor field to an ordered list of `{code, message}`
objects. Validation responses never contain submitted values, stack traces, or
validator internals, including when `APP_DEBUG` is enabled. For application
business rules, implement a DTO-specific custom validator and register it with
`ApplicationBuilder::withValidator(Dto::class, Validator::class)`; validators
can use services injected by the application container. Keep domain policy and
database checks in application-owned code.

Hydration does not recursively map nested DTOs and does not provide enum, union,
or general object conversion. Arbitrary payload validation and automatic
database checks are not part of this contract. Use explicit application code
or the PSR request/response interfaces when an endpoint needs those behaviors.
JSON errors use Problem Details; exception details are omitted unless
`APP_DEBUG` is enabled (validation details remain redacted in either mode).

At application boot, Tusk discovers PHP controllers in `app/Controller` and registers their route attributes. Use `ApplicationBuilder::withControllers()` to add controller directories; discovery happens once during boot. Controllers can also be wired through explicit route callbacks, or use PSR-7 request and response interfaces when full HTTP control is needed.

This package does not start an HTTP server. Tusk applications use the RoadRunner runtime for HTTP serving and worker lifecycle management.

## Installation
```bash
composer require tusk/web
```
