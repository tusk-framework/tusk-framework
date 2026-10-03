<?php

namespace Tusk\Web\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\Request;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\RouteMatch;
use Tusk\Web\Router\RouterInterface;

class HttpKernelTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
        putenv('APP_DEBUG');
        RecordingMiddleware::$lastRequest = null;
    }

    public function test_route_context_is_available_to_middleware(): void
    {
        $container = new TestContainer([
            TestController::class => new TestController(),
            RecordingMiddleware::class => new RecordingMiddleware(),
        ]);
        $kernel = new HttpKernel($container, new TestRouter());
        $kernel->addMiddleware(RecordingMiddleware::class);

        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull(RecordingMiddleware::$lastRequest);
        $this->assertSame(TestController::class, RecordingMiddleware::$lastRequest?->getAttribute('_controller'));
        $this->assertSame('handle', RecordingMiddleware::$lastRequest?->getAttribute('_action'));
    }

    public function test_json_errors_are_redacted_and_include_request_id(): void
    {
        $container = new TestContainer([ThrowingController::class => new ThrowingController()]);
        $kernel = new HttpKernel($container, new TestRouter(ThrowingController::class));
        $request = (new ServerRequest('GET', '/hello'))->withHeader('Accept', 'application/json');

        $response = $kernel->handle($request);
        $body = (string) $response->getBody();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Internal Server Error', $body);
        $this->assertStringNotContainsString('sensitive exception', $body);
        $this->assertStringNotContainsString(__FILE__, $body);
        $this->assertNotSame('', $response->getHeaderLine('X-Request-Id'));
    }
}

final class TestContainer implements ContainerInterface
{
    public function __construct(private array $services) {}

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException("Missing test service {$id}");
    }

    public function has(string $id): bool { return isset($this->services[$id]); }
    public function runHooks(string $attributeClass): void {}
    public function resetScope(string $scope): void {}
}

final class TestRouter implements RouterInterface
{
    public function __construct(private string $controller = TestController::class) {}

    public function match(string $method, string $uri): ?RouteMatch
    {
        return $uri === '/hello' ? new RouteMatch($this->controller, 'handle') : null;
    }
}

final class TestController
{
    public function handle(Request $request): string { return 'ok'; }
}

final class ThrowingController
{
    public function handle(Request $request): string { throw new \RuntimeException('sensitive exception'); }
}

final class RecordingMiddleware implements MiddlewareInterface
{
    public static ?ServerRequestInterface $lastRequest = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$lastRequest = $request;
        return $handler->handle($request);
    }
}
