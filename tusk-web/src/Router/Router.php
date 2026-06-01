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

        // 1. Try exact match first
        if (isset($this->routes[$method][$uri])) {
            return $this->buildMatch($this->routes[$method][$uri], []);
        }

        // 2. Try pattern matching for routes with placeholders (e.g. /users/{id})
        foreach ($this->routes[$method] ?? [] as $path => $route) {
            $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $this->buildMatch($route, $params);
            }
        }

        return null;
    }

    private function buildMatch(array $route, array $params): RouteMatch
    {
        $handler = $route['handler'];

        if (is_array($handler)) {
            [$controller, $action] = $handler;
        } else {
            // Support 'ControllerClass@method' and invokable 'ControllerClass'
            $segments = explode('@', $handler, 2);
            $controller = $segments[0];
            $action = $segments[1] ?? '__invoke';
        }

        return new RouteMatch(
            controller: $controller,
            method: $action,
            params: $params,
            middleware: $route['middleware'] ?? [],
        );
    }
}
