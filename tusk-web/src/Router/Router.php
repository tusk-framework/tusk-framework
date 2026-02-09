<?php

namespace Tusk\Web\Router;

use ReflectionClass;
use Tusk\Web\Attribute\Route;
use Tusk\Web\Http\Request;

class Router
{
    private array $routes = [];

    /**
     * Scans a list of controller classes and registers their routes.
     * 
     * @param string[] $controllers List of FQCNs
     */
    public function registerControllers(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $reflection = new ReflectionClass($controller);
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(Route::class);
                foreach ($attributes as $attribute) {
                    $route = $attribute->newInstance();
                    $this->addRoute($route->methods, $route->path, [$controller, $method->getName()]);
                }
            }
        }
    }

    public function addRoute(array $methods, string $path, callable|array $handler): void
    {
        foreach ($methods as $method) {
            $this->routes[strtoupper($method)][$path] = $handler;
        }
    }

    public function match(Request $request): ?array
    {
        $method = strtoupper($request->method);
        $path = parse_url($request->uri, PHP_URL_PATH);

        return $this->routes[$method][$path] ?? null;
    }
}
