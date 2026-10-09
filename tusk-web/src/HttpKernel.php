<?php

namespace Tusk\Web;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use Tusk\Config\Env;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\ArgumentBinder;
use Tusk\Web\Http\HttpException;
use Tusk\Web\Http\MiddlewarePipeline;
use Tusk\Web\Http\ValidationException;
use Tusk\Web\Router\RouteMatch;
use Tusk\Web\Router\RouterInterface;

class HttpKernel implements RequestHandlerInterface
{
    /** @var string[] */
    private array $globalMiddleware = [];

    private ArgumentBinder $argumentBinder;

    public function __construct(
        private ContainerInterface $container,
        private RouterInterface $router,
        ?ArgumentBinder $argumentBinder = null,
    ) {
        $this->argumentBinder = $argumentBinder ?? new ArgumentBinder;
    }

    public function addMiddleware(string $middlewareClass): self
    {
        $this->globalMiddleware[] = $middlewareClass;

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();

            $match = $this->router->match($method, $uri);
            if ($match) {
                $request = $request
                    ->withAttribute('_controller', $match->controller)
                    ->withAttribute('_action', $match->method);
            }

            // Core handler that finally executes the Controller
            $coreHandler = new class($this->container, $match, $this->argumentBinder) implements RequestHandlerInterface
            {
                public function __construct(
                    private ContainerInterface $container,
                    private ?RouteMatch $match,
                    private ArgumentBinder $argumentBinder,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    if (! $this->match) {
                        return new Response(404, [], 'Not Found');
                    }

                    $controllerClass = $this->match->controller;
                    $method = $this->match->method;

                    $controller = $this->container->get($controllerClass);
                    $response = $controller->$method(...$this->argumentBinder->bind(
                        new ReflectionMethod($controller, $method),
                        $request,
                        $this->match->params,
                        $this->container,
                    ));

                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    if (is_array($response) || is_object($response)) {
                        return new Response(200, ['Content-Type' => 'application/json'], json_encode($response, JSON_THROW_ON_ERROR));
                    }

                    if (is_string($response)) {
                        return new Response(200, ['Content-Type' => 'text/html'], $response);
                    }

                    return new Response(500, [], 'Invalid controller response type');
                }
            };

            $pipeline = new MiddlewarePipeline($coreHandler);

            // Pipe Global Middleware
            foreach ($this->globalMiddleware as $middlewareClass) {
                $pipeline->pipe($this->container->get($middlewareClass));
            }

            // Pipe Route Specific Middleware
            if ($match && ! empty($match->middleware)) {
                foreach ($match->middleware as $middlewareClass) {
                    $pipeline->pipe($this->container->get($middlewareClass));
                }
            }

            return $pipeline->handle($request);
        } catch (\Throwable $e) {
            $requestId = bin2hex(random_bytes(8));
            error_log(sprintf('[request:%s] %s: %s in %s:%d', $requestId, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
            $debug = self::isDebugEnabled();
            $headers = ['X-Request-Id' => $requestId];

            if (str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')) {
                $validationError = $e instanceof ValidationException;
                $payload = [
                    'type' => $validationError ? 'urn:tusk:problem:validation' : 'about:blank',
                    'title' => $e instanceof HttpException ? $e->getMessage() : 'Internal Server Error',
                    'status' => $status,
                    'instance' => (string) $request->getUri()->getPath(),
                    'request_id' => $requestId,
                ];
                if ($validationError) {
                    $payload['errors'] = $e->errors();
                } elseif ($debug) {
                    $payload['exception'] = get_class($e);
                    $payload['message'] = $e->getMessage();
                    $payload['file'] = $e->getFile();
                    $payload['line'] = $e->getLine();
                }

                return new Response($status, $headers + ['Content-Type' => 'application/problem+json'], json_encode($payload, JSON_THROW_ON_ERROR));
            }

            $title = $status === 500 ? 'Internal Server Error' : $e->getMessage();
            $details = '';
            if ($debug && ! $e instanceof ValidationException) {
                $details = sprintf(
                    '<div class="debug-info"><h2>%s</h2><p>%s</p><p>%s:%d</p><pre>%s</pre></div>',
                    htmlspecialchars(get_class($e), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $e->getLine(),
                    htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }

            $html = sprintf(
                '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>%d - %s</title></head><body><main><h1>%d - %s</h1><p>Request ID: %s</p>%s</main></body></html>',
                $status,
                htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $status,
                htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $details
            );

            return new Response($status, $headers + ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }
    }

    private static function isDebugEnabled(): bool
    {
        return in_array(strtolower(trim((string) Env::get('APP_DEBUG', 'false'))), ['1', 'true', 'yes', 'on'], true);
    }
}
