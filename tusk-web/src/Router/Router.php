<?php

namespace Tusk\Web\Router;

use ReflectionClass;
use Tusk\Web\Attribute\Route;

class Router implements RouterInterface
{
    private array $routes = [];

    /**
     * Scans a list of controller classes and registers their routes.
     *
     * @param  string[]  $controllers  List of FQCNs
     */
    public function registerControllers(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $reflection = new ReflectionClass($controller);
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(Route::class);
                foreach ($attributes as $attribute) {
                    $route = $attribute->newInstance();
                    $this->addRoute($route->methods, $route->path, [$controller, $method->getName()], $route->middleware);
                }
            }
        }
    }

    public function addRoute(array $methods, string $path, callable|array $handler, array $middleware = []): void
    {
        foreach ($methods as $method) {
            $this->routes[strtoupper($method)][$path] = [
                'handler'    => $handler,
                'middleware' => $middleware,
            ];
        }
    }

    public function match(string $method, string $uri): ?RouteMatch
    {
        $method = strtoupper($method);

        $route = $this->routes[$method][$uri] ?? null;

        if (!$route) {
            return null;
        }

        $handler = $route['handler'];

        [$controller, $action] = is_array($handler) ? $handler : explode('@', $handler);

        return new RouteMatch(
            controller: $controller,
            method: $action,
            params: [],
            middleware: $route['middleware'] ?? [],
        );
    }
}
