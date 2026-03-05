<?php

namespace Tusk\Web;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Web\Router\Router;
use Tusk\Contracts\Web\MiddlewareInterface;
use Tusk\Contracts\Web\RequestHandlerInterface;

class HttpKernel
{
    /** @var class-string<MiddlewareInterface>[] */
    private array $middleware = [];

    public function __construct(
        private ContainerInterface $container,
        private Router $router
    ) {
    }

    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    public function prependMiddleware(string $middleware): self
    {
        array_unshift($this->middleware, $middleware);
        return $this;
    }

    /**
     * @param class-string<MiddlewareInterface> $middleware
     */
    public function pushMiddleware(string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function handle(Request $request): Response
    {
        return $this->sendRequestThroughMiddleware($request);
    }

    private function sendRequestThroughMiddleware(Request $request): Response
    {
        $match = $this->router->match($request);
        $routeMiddleware = $match ? ($match['middleware'] ?? []) : [];
        $fullMiddlewareStack = array_merge($this->middleware, $routeMiddleware);

        $handler = new class ($this->container, $fullMiddlewareStack, function (Request $request) use ($match) {
            return $this->dispatchToRoute($request, $match);
        }) implements RequestHandlerInterface {
            private int $index = 0;

            public function __construct(
            private ContainerInterface $container,
            private array $middleware,
            private \Closure $coreHandler
            ) {
            }

            public function handle(Request $request): Response
            {
                if ($this->index >= count($this->middleware)) {
                    return ($this->coreHandler)($request);
                }

                $middlewareClass = $this->middleware[$this->index];
                $this->index++;

                /** @var \Tusk\Contracts\Web\MiddlewareInterface $middleware */
                $middleware = $this->container->get($middlewareClass);

                return $middleware->process($request, $this);
            }
        };

        return $handler->handle($request);
    }

    private function dispatchToRoute(Request $request, ?array $match): Response
    {
        if (!$match) {
            return new Response(404, [], 'Not Found');
        }

        $handler = $match['handler'];

        // Handler is [ControllerClass, methodName]
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            $controllerClass = $handler[0];
            $method = $handler[1];

            // Resolve controller from DI container
            $controllerInstance = $this->container->get($controllerClass);

            // Execute method
            $response = $controllerInstance->$method($request);

            if ($response instanceof ResponsableInterface) {
                $response = $response->toResponse($request);
            }

            if (is_array($response)) {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
            }

            if (is_string($response)) {
                return new Response(200, ['Content-Type' => 'text/html'], $response);
            }

            if (!$response instanceof Response) {
                return new Response(500, [], "Controller must return a Response, string, or array.");
            }

            return $response;
        }

        return new Response(500, [], "Invalid handler.");
    }
}
