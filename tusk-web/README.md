# Tusk Web

The **Tusk Web** package provides the HTTP abstractions, routing, and controller logic for the Tusk Framework.

## Features
- **Routing**: High-performance attribute-based and functional routing.
- **Convention-first controllers**: Use `#[Controller]`, `#[Get]`, `#[Post]`, and typed request DTOs.
- **Controllers**: Clean, testable controller architecture.
- **Middleware**: PSR-15 compliant middleware support.

## Recommended controller style

```php
#[Controller('/users')]
final class UserController
{
    public function __construct(private UserService $users) {}

    #[Get]
    public function list(): array
    {
        return $this->users->all();
    }
}
```

The framework binds route values and typed request DTOs, serializes arrays/objects as JSON, and returns RFC 9457-style problem details for JSON errors. Low-level PSR-7 handlers remain available when an application needs full control.

## Installation
```bash
composer require tusk/web
```
