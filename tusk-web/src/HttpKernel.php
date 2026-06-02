<?php

namespace Tusk\Web;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\MiddlewarePipeline;
use Tusk\Web\Router\RouterInterface;

class HttpKernel implements RequestHandlerInterface
{
    /** @var string[] */
    private array $globalMiddleware = [];

    public function __construct(
        private ContainerInterface $container,
        private RouterInterface $router
    ) {}

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
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $match = $this->router->match($method, $uri);

        // Core handler that finally executes the Controller
        $coreHandler = new class($this->container, $match) implements RequestHandlerInterface {
            public function __construct(
                private ContainerInterface $container,
                private ?\Tusk\Web\Router\RouteMatch $match
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if (!$this->match) {
                    return new Response(404, [], 'Not Found');
                }

                $controllerClass = $this->match->controller;
                $method = $this->match->method;

                $controller = $this->container->get($controllerClass);
                $response = $controller->$method($request, ...array_values($this->match->params));

                if (is_array($response)) {
                    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($response));
                }

                if (is_string($response)) {
                    return new Response(200, ['Content-Type' => 'text/html'], $response);
                }

                if ($response instanceof ResponseInterface) {
                    return $response;
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
        if ($match && !empty($match->middleware)) {
            foreach ($match->middleware as $middlewareClass) {
                $pipeline->pipe($this->container->get($middlewareClass));
            }
        }

        return $pipeline->handle($request);
    }
}
