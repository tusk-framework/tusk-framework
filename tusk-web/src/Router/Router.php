<?php

namespace Tusk\Web\Router;

use ReflectionAttribute;
use ReflectionClass;
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Route;

class Router implements RouterInterface
{
    private array $routes = [];

    private int $routeSequence = 0;

    /**
     * Scans a list of controller classes and registers their routes.
     *
     * @param  string[]  $controllers  List of FQCNs
     */
    public function registerControllers(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $reflection = new ReflectionClass($controller);
            $prefix = '';
            $controllerAttributes = $reflection->getAttributes(Controller::class);
            if ($controllerAttributes !== []) {
                $prefix = $controllerAttributes[0]->newInstance()->prefix;
            }

            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
                foreach ($attributes as $attribute) {
                    $route = $attribute->newInstance();
                    $this->addRoute($route->methods, $this->joinPaths($prefix, $route->path), [$controller, $method->getName()], $route->middleware);
                }
            }
        }
    }

    public function addRoute(array $methods, string $path, callable|array $handler, array $middleware = []): void
    {
        foreach ($methods as $method) {
            $this->routes[strtoupper($method)][$path] = [
                'handler' => $handler,
                'middleware' => $middleware,
                'sequence' => ++$this->routeSequence,
            ];
        }
    }

    public function hasRoute(string $method, string $path): bool
    {
        return isset($this->routes[strtoupper($method)][$path]);
    }

    /** @return list<array{controller: class-string, method: non-empty-string}> */
    public function controllerActions(): array
    {
        $actions = [];
        $seen = [];
        $registeredRoutes = [];
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                $registeredRoutes[] = $route;
            }
        }
        usort($registeredRoutes, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        foreach ($registeredRoutes as $route) {
            $handler = $route['handler'];
            if (! is_array($handler) || count($handler) !== 2
                || ! is_string($handler[0]) || ! is_string($handler[1]) || $handler[1] === '') {
                continue;
            }

            $key = $handler[0].'::'.$handler[1];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $actions[] = ['controller' => $handler[0], 'method' => $handler[1]];
        }

        return $actions;
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
            $pattern = '#^'.$pattern.'$#';

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

    private function joinPaths(string $prefix, string $path): string
    {
        $fullPath = trim($prefix, '/').'/'.trim($path, '/');
        $fullPath = '/'.trim($fullPath, '/');

        return $fullPath === '/' || $fullPath === '/?' ? '/' : rtrim($fullPath, '/');
    }
}
