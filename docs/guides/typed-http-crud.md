# Typed HTTP CRUD

This guide shows the supported Tusk path from application bootstrap to a small
typed JSON CRUD API. It uses the generated project layout, attribute routes,
constructor injection, immutable request DTOs, validation, and ordinary PSR-7
responses. The example catalog stores data in memory to keep the walkthrough
self-contained; replace it with a database-backed application service for real
data. An in-memory service is reset when the process restarts and is not a
production persistence strategy.

## Start with a generated project

Create a project and generate its conventional controller:

```sh
tusk init catalog
cd catalog
composer install
tusk make:controller ProductController
```

These commands use the Tusk Engine CLI for project creation and Framework
command dispatch, and work in PowerShell, Git Bash, macOS, and Linux when the
Engine, PHP, and Composer are installed. The controller generator creates
`app/Controller/ProductController.php`; controller attributes in
`app/Controller` are discovered once during application bootstrap. The
generated `bootstrap/app.php` already loads `bootstrap/providers.php`.

Add the following application-owned files.

## Request and response data

`app/DTO/ProductInput.php`:

```php
<?php

namespace App\DTO;

use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;

final readonly class ProductInput
{
    public function __construct(
        #[NotBlank]
        #[Length(max: 120)]
        public string $name,
        #[NotBlank]
        #[Length(max: 40)]
        public string $sku,
    ) {}
}
```

`app/DTO/Product.php`:

```php
<?php

namespace App\DTO;

final readonly class Product
{
    public function __construct(public int $id, public string $name, public string $sku) {}
}
```

DTOs are flat constructor objects. Tusk binds values from the parsed request
body, converts supported scalar types, then applies the declared constraints.
The HTTP server or middleware is responsible for parsing a JSON body before it
reaches the application.

Scalar binding supports `int`, `float`, and `bool` through PHP's
`FILTER_VALIDATE_*` filters; `string` accepts a scalar and casts it, while
`array` requires an array. Missing fields use a constructor default when one
exists. A missing required field or an invalid scalar conversion is a `422`
binding error. This phase does not hydrate nested DTOs, enums, or union types.

Constraint failures also return `422`, with a stable RFC 9457 error envelope
and field-specific codes/messages (never submitted values):

```json
{
  "type": "urn:tusk:problem:validation",
  "title": "Validation Failed",
  "status": 422,
  "instance": "/api/products",
  "request_id": "…",
  "errors": {
    "name": [{"code": "not_blank", "message": "This value should not be blank."}]
  }
}
```

## Application service

`app/Application/ProductCatalog.php`:

```php
<?php

namespace App\Application;

use App\DTO\Product;

final class ProductCatalog
{
    /** @var array<int, Product> */
    private array $products = [];
    private int $nextId = 1;

    /** @return list<Product> */
    public function all(): array
    {
        return array_values($this->products);
    }

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function create(string $name, string $sku): Product
    {
        $product = new Product($this->nextId++, $name, $sku);
        $this->products[$product->id] = $product;

        return $product;
    }

    public function update(int $id, string $name, string $sku): ?Product
    {
        if (! isset($this->products[$id])) {
            return null;
        }

        return $this->products[$id] = new Product($id, $name, $sku);
    }

    public function delete(int $id): bool
    {
        if (! isset($this->products[$id])) {
            return false;
        }

        unset($this->products[$id]);

        return true;
    }
}
```

Register the service in `bootstrap/providers.php` using the generated
`Container` provider argument:

```php
use App\Application\ProductCatalog;
use Tusk\Core\Container\Container;

return static function (Container $container): void {
    // Keep any registrations already generated for the project.
    $container->register(ProductCatalog::class, 'singleton');
};
```

The same registration can construct a service with dependencies already
registered in the container. For a real application, have this service call a
repository or persistence adapter rather than keeping records in its own
process memory.

## Controller

Replace the generated controller with `app/Controller/ProductController.php`:

```php
<?php

namespace App\Controller;

use App\Application\ProductCatalog;
use App\DTO\ProductInput;
use Psr\Http\Message\ResponseInterface;
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Delete;
use Tusk\Web\Attribute\Get;
use Tusk\Web\Attribute\Post;
use Tusk\Web\Attribute\Put;
use Tusk\Web\Http\Response;

#[Controller('/api/products')]
final class ProductController
{
    public function __construct(private ProductCatalog $products) {}

    #[Get]
    public function index(): array
    {
        return $this->products->all();
    }

    #[Post]
    public function create(ProductInput $input): ResponseInterface
    {
        return Response::json($this->products->create($input->name, $input->sku), 201);
    }

    #[Get('/{id}')]
    public function show(int $id): ResponseInterface
    {
        $product = $this->products->find($id);

        return $product === null
            ? Response::json(['message' => 'Product not found'], 404)
            : Response::json($product);
    }

    #[Put('/{id}')]
    public function update(int $id, ProductInput $input): ResponseInterface
    {
        $product = $this->products->update($id, $input->name, $input->sku);

        return $product === null
            ? Response::json(['message' => 'Product not found'], 404)
            : Response::json($product);
    }

    #[Delete('/{id}')]
    public function delete(int $id): ResponseInterface
    {
        return $this->products->delete($id)
            ? new \Nyholm\Psr7\Response(204)
            : Response::json(['message' => 'Product not found'], 404);
    }
}
```

Controllers are resolved from the application container, so their constructor
dependencies remain explicit. DTOs and domain/application services stay
application-owned; Tusk provides the routing, binding, validation, and response
conversion around them.

Returning an array or object from an action produces a `200` JSON response;
returning a string produces an HTML response. Use `Response::json($data,
$status)` when the endpoint needs an explicit status or JSON header, as in the
create action above. Any returned PSR-7 `ResponseInterface` is passed through
without changing its status, headers, or body.

## Exercise the API

Run the project using the normal Tusk Engine/RoadRunner workflow, then try:

```sh
tusk config:validate
tusk start
```

In a second terminal, send requests to the generated HTTP port (default
`8080`):

```sh
curl -i http://127.0.0.1:8080/api/products
curl -i -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"name":"Notebook","sku":"NOTE-1"}' http://127.0.0.1:8080/api/products
curl -i http://127.0.0.1:8080/api/products/1
curl -i -X PUT -H 'Content-Type: application/json' -d '{"name":"Journal","sku":"JOURNAL-1"}' http://127.0.0.1:8080/api/products/1
curl -i -X DELETE http://127.0.0.1:8080/api/products/1
```

On Windows PowerShell, use `curl.exe` in place of `curl` (Windows PowerShell
may define `curl` as an alias).

For an invalid name, the API returns `422` with
`Content-Type: application/problem+json` and an RFC 9457 problem containing a
stable `errors.name` entry. Missing required DTO fields also return `422` as a
binding error. Neither response echoes submitted values. A missing product is
an application-level `404` response. The integration test
`tests/Integration/TypedCrudApplicationIntegrationTest.php` boots this same
attribute-controller, provider, injection, DTO, and CRUD flow without relying
on the RoadRunner process.

## PSR-7 escape hatch

When an endpoint needs a header, cookie, upload, or another transport detail,
accept `Psr\Http\Message\ServerRequestInterface` (or Tusk's `Request`) in the
action. Returning any `Psr\Http\Message\ResponseInterface` passes its status,
headers, and body through unchanged. The convention-based DTO path is for the
common case, not a replacement for PSR-7.
