<?php

namespace Tusk\Web\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\ArgumentBinder;
use Tusk\Web\Http\Request;

final class ArgumentBinderTest extends TestCase
{
    public function test_binds_route_scalars_request_wrapper_and_typed_body_dto(): void
    {
        $request = (new ServerRequest('POST', '/users/42'))
            ->withParsedBody(['name' => 'Ana']);

        $arguments = (new ArgumentBinder())->bind(
            new ReflectionMethod(BindableController::class, 'create'),
            $request,
            ['id' => '42'],
            new EmptyContainer(),
        );

        $this->assertSame(42, $arguments[0]);
        $this->assertInstanceOf(CreateUserRequest::class, $arguments[1]);
        $this->assertSame('Ana', $arguments[1]->name);
        $this->assertInstanceOf(Request::class, $arguments[2]);
    }
}

final class BindableController
{
    public function create(int $id, CreateUserRequest $input, Request $request): array
    {
        return compact('id', 'input', 'request');
    }
}

final readonly class CreateUserRequest
{
    public function __construct(public string $name) {}
}

final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): object { throw new \RuntimeException("Missing {$id}"); }
    public function has(string $id): bool { return false; }
    public function runHooks(string $attributeClass): void {}
    public function runLifecycleHooks(string $event): void {}
    public function resetScope(string $scope): void {}
}
