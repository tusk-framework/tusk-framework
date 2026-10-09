<?php

namespace Tusk\Web\Tests;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
            TestController::class => new TestController,
            RecordingMiddleware::class => new RecordingMiddleware,
        ]);
        $kernel = new HttpKernel($container, new TestRouter);
        $kernel->addMiddleware(RecordingMiddleware::class);

        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull(RecordingMiddleware::$lastRequest);
        $this->assertSame(TestController::class, RecordingMiddleware::$lastRequest->getAttribute('_controller'));
        $this->assertSame('handle', RecordingMiddleware::$lastRequest->getAttribute('_action'));
    }

    public function test_json_errors_are_redacted_and_include_request_id(): void
    {
        $container = new TestContainer([ThrowingController::class => new ThrowingController]);
        $kernel = new HttpKernel($container, new TestRouter(ThrowingController::class));
        $request = (new ServerRequest('GET', '/hello'))->withHeader('Accept', 'application/json');

        $response = $kernel->handle($request);
        $body = (string) $response->getBody();

        $problem = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['type', 'title', 'status', 'instance', 'request_id'], array_keys($problem));
        $this->assertSame('Internal Server Error', $problem['title']);
        $this->assertSame(500, $problem['status']);
        $this->assertSame('/hello', $problem['instance']);
        $this->assertStringNotContainsString('sensitive exception', $body);
        $this->assertStringNotContainsString(__FILE__, $body);
        $this->assertSame($response->getHeaderLine('X-Request-Id'), $problem['request_id']);
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function test_json_errors_include_exception_details_when_debug_is_enabled(): void
    {
        putenv('APP_DEBUG=1');
        $container = new TestContainer([ThrowingController::class => new ThrowingController]);
        $kernel = new HttpKernel($container, new TestRouter(ThrowingController::class));
        $request = (new ServerRequest('GET', '/hello'))->withHeader('Accept', 'application/json');

        $response = $kernel->handle($request);
        $problem = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('RuntimeException', $problem['exception']);
        $this->assertSame('sensitive exception', $problem['message']);
        $this->assertSame(__FILE__, $problem['file']);
        $this->assertIsInt($problem['line']);
    }

    public function test_missing_required_dto_field_returns_redacted_422_problem_details(): void
    {
        $container = new TestContainer([RequiredInputController::class => new RequiredInputController]);
        $kernel = new HttpKernel($container, new TestRouter(RequiredInputController::class, 'create'));
        $request = (new ServerRequest('POST', '/hello'))
            ->withParsedBody([])
            ->withHeader('Accept', 'application/json');

        $response = $kernel->handle($request);
        $problem = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('about:blank', $problem['type']);
        $this->assertSame('Missing request field: name', $problem['title']);
        $this->assertSame(422, $problem['status']);
        $this->assertSame('/hello', $problem['instance']);
        $this->assertSame($response->getHeaderLine('X-Request-Id'), $problem['request_id']);
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertArrayNotHasKey('exception', $problem);
        $this->assertArrayNotHasKey('message', $problem);
        $this->assertArrayNotHasKey('file', $problem);
        $this->assertArrayNotHasKey('line', $problem);
    }

    public function test_typed_object_controller_results_are_serialized_as_json(): void
    {
        $container = new TestContainer([TypedController::class => new TypedController]);
        $kernel = new HttpKernel($container, new TestRouter(TypedController::class, 'show'));

        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"id":7,"name":"Ada"}', (string) $response->getBody());
    }

    public function test_array_controller_results_are_serialized_as_json(): void
    {
        $container = new TestContainer([ArrayController::class => new ArrayController]);
        $kernel = new HttpKernel($container, new TestRouter(ArrayController::class, 'index'));

        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"items":["a","b"],"count":2}', (string) $response->getBody());
    }

    public function test_string_controller_results_are_returned_as_html(): void
    {
        $container = new TestContainer([StringController::class => new StringController]);
        $kernel = new HttpKernel($container, new TestRouter(StringController::class, 'show'));

        $response = $kernel->handle(new ServerRequest('GET', '/hello'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function test_psr_response_is_passed_through_without_changing_its_body_or_headers(): void
    {
        $expected = new Psr7Response(202, ['Content-Type' => 'application/custom', 'X-Result' => 'kept'], '{ "accepted" : true }');
        $container = new TestContainer([PassthroughController::class => new PassthroughController($expected)]);
        $kernel = new HttpKernel($container, new TestRouter(PassthroughController::class, 'create'));

        $response = $kernel->handle(new ServerRequest('POST', '/hello'));

        $this->assertSame($expected, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('application/custom', $response->getHeaderLine('Content-Type'));
        $this->assertSame('kept', $response->getHeaderLine('X-Result'));
        $this->assertSame('{ "accepted" : true }', (string) $response->getBody());
    }

    public function test_json_encoding_failure_returns_a_server_error_instead_of_malformed_json(): void
    {
        $container = new TestContainer([UnencodableController::class => new UnencodableController]);
        $kernel = new HttpKernel($container, new TestRouter(UnencodableController::class, 'show'));
        $request = (new ServerRequest('GET', '/hello'))->withHeader('Accept', 'application/json');

        $response = $kernel->handle($request);
        $problem = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('/hello', $problem['instance']);
        $this->assertSame($response->getHeaderLine('X-Request-Id'), $problem['request_id']);
        $this->assertSame('Internal Server Error', $problem['title']);
        $this->assertArrayNotHasKey('exception', $problem);
        $this->assertArrayNotHasKey('message', $problem);
    }
}

final class TestContainer implements ContainerInterface
{
    public function __construct(private array $services) {}

    public function instance(string $id, object $instance): void {}

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException("Missing test service {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function runHooks(string $attributeClass): void {}

    public function runLifecycleHooks(string $event): void {}

    public function resetScope(string $scope): void {}
}

final class TestRouter implements RouterInterface
{
    public function __construct(
        private string $controller = TestController::class,
        private string $method = 'handle',
    ) {}

    public function match(string $method, string $uri): ?RouteMatch
    {
        return $uri === '/hello' ? new RouteMatch($this->controller, $this->method) : null;
    }
}

final class TestController
{
    public function handle(Request $request): string
    {
        return 'ok';
    }
}

final class ThrowingController
{
    public function handle(Request $request): string
    {
        throw new \RuntimeException('sensitive exception');
    }
}

final class TypedController
{
    public function show(): object
    {
        return new UserView(7, 'Ada');
    }
}

final readonly class UserView
{
    public function __construct(public int $id, public string $name) {}
}

final class ArrayController
{
    public function index(): array
    {
        return ['items' => ['a', 'b'], 'count' => 2];
    }
}

final class StringController
{
    public function show(): string
    {
        return '<h1>Hello</h1>';
    }
}

final class PassthroughController
{
    public function __construct(private ResponseInterface $response) {}

    public function create(): ResponseInterface
    {
        return $this->response;
    }
}

final class UnencodableController
{
    public function show(): object
    {
        return (object) ['name' => "Invalid \xB1 UTF-8"];
    }
}

final class RequiredInputController
{
    public function create(RequiredInput $input): RequiredInput
    {
        return $input;
    }
}

final readonly class RequiredInput
{
    public function __construct(public string $name) {}
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
