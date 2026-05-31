<?php

namespace Tests\Unit\Web;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\Request;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\RouteMatch;
use Tusk\Web\Router\RouterInterface;

class HttpKernelCompatibilityTest extends TestCase
{
    public function test_handles_controller_actions_that_still_type_hint_legacy_request(): void
    {
        $controller = new class {
            public function show(Request $request, string $id): string
            {
                return $request->method.':'.$request->uri.':'.$request->get('page').':'.$id;
            }
        };

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): object
            {
                return $this->controller;
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function runHooks(string $attributeClass): void {}

            public function resetScope(string $scope): void {}
        };

        $router = new class implements RouterInterface {
            public function match(string $method, string $uri): ?RouteMatch
            {
                return new RouteMatch('LegacyController', 'show', ['id' => '42']);
            }
        };

        $kernel = new HttpKernel($container, $router);
        $request = new ServerRequest('GET', '/users/42?page=2');

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET:/users/42:2:42', (string) $response->getBody());
    }
}
