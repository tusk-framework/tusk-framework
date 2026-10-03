<?php

namespace Tusk\Security\Tests\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tusk\Security\Authorization\Gate;
use Tusk\Security\Attribute\Authenticated;
use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Contract\UserInterface;
use Tusk\Security\Middleware\SecurityMiddleware;
use Tusk\Web\Http\HttpException;

class SecurityMiddlewareTest extends TestCase
{
    public function test_authenticated_attribute_returns_401_when_guard_denies(): void
    {
        $guard = new DenyGuard();
        $middleware = new SecurityMiddleware($guard, new Gate($guard));
        $request = (new ServerRequest('GET', '/'))->withAttribute('_controller', ProtectedController::class)->withAttribute('_action', 'handle');

        try {
            $middleware->process($request, new PassThroughHandler());
            $this->fail('Expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }
}

#[Authenticated]
final class ProtectedController
{
    public function handle(): ResponseInterface { return new Response(200); }
}

final class DenyGuard implements GuardInterface
{
    public function check(): bool { return false; }
    public function user(): ?UserInterface { return null; }
    public function setUser(UserInterface $user): void {}
}

final class PassThroughHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(200); }
}
