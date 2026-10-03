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
}
