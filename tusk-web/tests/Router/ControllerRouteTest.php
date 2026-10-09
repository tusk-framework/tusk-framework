<?php

namespace Tusk\Web\Tests\Router;

use PHPUnit\Framework\TestCase;
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Get;
use Tusk\Web\Attribute\Post;
use Tusk\Web\Router\Router;

final class ControllerRouteTest extends TestCase
{
    public function test_controller_prefix_and_http_verb_attributes_are_compiled_into_routes(): void
    {
        $router = new Router();
        $router->registerControllers([AttributeController::class]);

        $list = $router->match('GET', '/users');
        $this->assertNotNull($list);
        $this->assertSame(AttributeController::class, $list->controller);
        $this->assertSame('list', $list->method);

        $show = $router->match('POST', '/users/42');
        $this->assertNotNull($show);
        $this->assertSame('create', $show->method);
        $this->assertSame(['id' => '42'], $show->params);
    }

    public function test_controller_actions_enumerates_explicit_and_discovered_handlers_once_in_route_order(): void
    {
        $router = new Router;
        $router->addRoute(['GET', 'POST'], '/manual', [AttributeController::class, 'list']);
        $router->addRoute(['GET'], '/other', [AttributeController::class, 'list']);
        $router->addRoute(['GET'], '/closure', static fn (): array => []);
        $router->registerControllers([AttributeController::class]);

        self::assertSame([
            ['controller' => AttributeController::class, 'method' => 'list'],
            ['controller' => AttributeController::class, 'method' => 'create'],
        ], $router->controllerActions());
        self::assertSame('list', $router->match('GET', '/manual')->method);
        self::assertSame('create', $router->match('POST', '/users/42')->method);
    }

    public function test_controller_actions_follow_registration_order_across_http_methods(): void
    {
        $router = new Router;
        $router->addRoute(['GET'], '/a', [AttributeController::class, 'list']);
        $router->addRoute(['POST'], '/b', [AttributeController::class, 'create']);
        $router->addRoute(['GET'], '/c', [AttributeController::class, 'remove']);

        self::assertSame([
            ['controller' => AttributeController::class, 'method' => 'list'],
            ['controller' => AttributeController::class, 'method' => 'create'],
            ['controller' => AttributeController::class, 'method' => 'remove'],
        ], $router->controllerActions());
    }

    public function test_controller_actions_expose_only_the_current_handler_for_an_overwritten_route(): void
    {
        $router = new Router;
        $router->addRoute(['GET'], '/a', [AttributeController::class, 'list']);
        $router->addRoute(['POST'], '/b', [AttributeController::class, 'create']);
        $router->addRoute(['GET'], '/a', [AttributeController::class, 'remove']);

        self::assertSame([
            ['controller' => AttributeController::class, 'method' => 'create'],
            ['controller' => AttributeController::class, 'method' => 'remove'],
        ], $router->controllerActions());
        self::assertSame('remove', $router->match('GET', '/a')->method);
    }
}

#[Controller('/users')]
final class AttributeController
{
    #[Get]
    public function list(): array
    {
        return [];
    }

    #[Post('/{id}')]
    public function create(): array
    {
        return [];
    }

    public function remove(): array
    {
        return [];
    }
}
