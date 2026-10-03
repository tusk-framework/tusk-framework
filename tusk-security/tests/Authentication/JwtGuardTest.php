<?php

namespace Tusk\Security\Tests\Authentication;

use Firebase\JWT\JWT;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Security\Authentication\JwtGuard;
use Tusk\Security\User\InMemoryUser;
use Tusk\Security\User\InMemoryUserProvider;
use Tusk\Web\Http\Request;

class JwtGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET'], $_SERVER['JWT_SECRET']);
    }

    public function test_missing_or_short_secret_fails_closed(): void
    {
        $guard = $this->guardFor(new ServerRequest('GET', '/'));

        $this->assertFalse($guard->check());

        putenv('JWT_SECRET=short');
        $this->assertFalse($this->guardFor(new ServerRequest('GET', '/'))->check());
    }

    public function test_bearer_token_authenticates_with_valid_secret(): void
    {
        $secret = str_repeat('s', 32);
        putenv('JWT_SECRET='.$secret);
        $token = JWT::encode(['sub' => '1', 'exp' => time() + 300], $secret, 'HS256');

        $guard = $this->guardFor(new ServerRequest('GET', '/', ['Authorization' => 'Bearer '.$token]));

        $this->assertTrue($guard->check());
        $this->assertSame('1', (string) $guard->user()?->getIdentifier());
    }

    public function test_query_token_is_disabled_by_default(): void
    {
        $secret = str_repeat('s', 32);
        putenv('JWT_SECRET='.$secret);
        $token = JWT::encode(['sub' => '1', 'exp' => time() + 300], $secret, 'HS256');

        $guard = $this->guardFor(new ServerRequest('GET', '/?token='.urlencode($token)));

        $this->assertFalse($guard->check());
    }

    private function guardFor(ServerRequest $request): JwtGuard
    {
        $provider = new InMemoryUserProvider([new InMemoryUser('1')]);
        return new JwtGuard($provider, new Request($request));
    }
}
