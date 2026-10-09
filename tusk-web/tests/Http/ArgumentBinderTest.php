<?php

namespace Tusk\Web\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\ArgumentBinder;
use Tusk\Web\Http\HttpException;
use Tusk\Web\Http\Request;

final class ArgumentBinderTest extends TestCase
{
    public function test_binds_route_scalars_request_wrapper_and_typed_body_dto(): void
    {
        $request = (new ServerRequest('POST', '/users/42'))
            ->withParsedBody(['name' => 'Ana']);

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'create'),
            $request,
            ['id' => '42'],
            new EmptyContainer,
        );

        $this->assertSame(42, $arguments[0]);
        $this->assertInstanceOf(CreateUserRequest::class, $arguments[1]);
        $this->assertSame('Ana', $arguments[1]->name);
        $this->assertInstanceOf(Request::class, $arguments[2]);
    }

    public function test_injects_the_original_psr_server_request(): void
    {
        $request = new ServerRequest('GET', '/users');

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'withPsrRequest'),
            $request,
            [],
            new EmptyContainer,
        );

        $this->assertSame($request, $arguments[0]);
    }

    public function test_hydrates_readonly_dto_scalars_and_preserves_constructor_defaults(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody([
                'quantity' => '3',
                'price' => '12.50',
                'active' => 'false',
                'name' => 'Widget',
                'tags' => ['featured', 'new'],
            ]);

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'createProduct'),
            $request,
            [],
            new EmptyContainer,
        );

        $input = $arguments[0];
        $this->assertInstanceOf(ProductRequest::class, $input);
        $this->assertSame(3, $input->quantity);
        $this->assertSame(12.5, $input->price);
        $this->assertFalse($input->active);
        $this->assertSame('Widget', $input->name);
        $this->assertSame('standard', $input->category);
        $this->assertSame(['featured', 'new'], $input->tags);
    }

    public function test_missing_required_dto_field_is_a_stable_422_error(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody(['quantity' => '3']);

        try {
            (new ArgumentBinder)->bind(
                new ReflectionMethod(BindableController::class, 'createProduct'),
                $request,
                [],
                new EmptyContainer,
            );
            self::fail('Expected a missing required DTO field to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Missing request field: price', $exception->getMessage());
        }
    }

    public function test_invalid_dto_scalar_is_a_stable_422_error(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody([
                'quantity' => 'many',
                'price' => '12.50',
                'active' => 'false',
                'name' => 'Widget',
            ]);

        try {
            (new ArgumentBinder)->bind(
                new ReflectionMethod(BindableController::class, 'createProduct'),
                $request,
                [],
                new EmptyContainer,
            );
            self::fail('Expected an invalid DTO scalar to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Invalid value for argument: quantity', $exception->getMessage());
        }
    }
}

final class BindableController
{
    public function create(int $id, CreateUserRequest $input, Request $request): array
    {
        return compact('id', 'input', 'request');
    }

    public function createProduct(ProductRequest $input): ProductRequest
    {
        return $input;
    }

    public function withPsrRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }
}

final readonly class CreateUserRequest
{
    public function __construct(public string $name) {}
}

final readonly class ProductRequest
{
    public function __construct(
        public int $quantity,
        public float $price,
        public bool $active,
        public string $name,
        public string $category = 'standard',
        public array $tags = [],
    ) {}
}

final class EmptyContainer implements ContainerInterface
{
    public function instance(string $id, object $instance): void {}

    public function get(string $id): object
    {
        throw new \RuntimeException("Missing {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }

    public function runHooks(string $attributeClass): void {}

    public function runLifecycleHooks(string $event): void {}

    public function resetScope(string $scope): void {}
}
