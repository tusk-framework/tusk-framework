<?php

namespace Tusk\Web\Tests\Router;

use PHPUnit\Framework\TestCase;
use Tusk\Web\Router\RouteCompiler;

class CompiledRouterTest extends TestCase
{
    private string $routerClass;

    protected function setUp(): void
    {
        $compiler = new RouteCompiler();
        
        $routes = [
            'GET' => [
                '#^/users$#' => [
                    'class' => 'UserController',
                    'method' => 'index',
                    'middleware' => []
                ],
                '#^/users/(?P<id>[^/]+)$#' => [
                    'class' => 'UserController',
                    'method' => 'show',
                    'middleware' => []
                ]
            ]
        ];

        $uid = uniqid();
        $this->routerClass = 'TestCompiledRouter_' . $uid;
        
        $code = $compiler->compile($routes, 'Tusk\TestRouter', $this->routerClass);
        eval(str_replace('<?php', '', $code));
    }

    public function test_matches_static_routes(): void
    {
        $class = '\\Tusk\\TestRouter\\' . $this->routerClass;
        $router = new $class();
        
        $match = $router->match('GET', '/users');
        
        $this->assertNotNull($match);
        $this->assertEquals('UserController', $match->controller);
        $this->assertEquals('index', $match->method);
        $this->assertEmpty($match->params);
    }

    public function test_matches_dynamic_routes_and_extracts_parameters(): void
    {
        $class = '\\Tusk\\TestRouter\\' . $this->routerClass;
        $router = new $class();
        
        $match = $router->match('GET', '/users/123');

        $this->assertNotNull($match);
        $this->assertEquals('UserController', $match->controller);
        $this->assertEquals('show', $match->method);
        $this->assertEquals(['id' => '123'], $match->params);
    }

    public function test_returns_null_when_route_not_found(): void
    {
        $class = '\\Tusk\\TestRouter\\' . $this->routerClass;
        $router = new $class();
        
        $this->assertNull($router->match('POST', '/users'));
        $this->assertNull($router->match('GET', '/posts'));
    }
}
