<?php

namespace Tusk\Security\Tests\Authentication;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Security\Authentication\TokenGuard;
use Tusk\Security\User\InMemoryUser;
use Tusk\Security\User\InMemoryUserProvider;
use Tusk\Web\Http\Request;

class TokenGuardTest extends TestCase
{
    public function test_bearer_token_is_resolved_from_request_header(): void
    {
        $guard = new TokenGuard(
            new InMemoryUserProvider([new InMemoryUser('abc')]),
            new Request(new ServerRequest('GET', '/', ['Authorization' => 'Bearer abc']))
        );

        $this->assertTrue($guard->check());
        $this->assertSame('abc', (string) $guard->user()?->getIdentifier());
    }

    public function test_query_token_requires_explicit_opt_in(): void
    {
        $guard = new TokenGuard(
            new InMemoryUserProvider([new InMemoryUser('abc')]),
            new Request(new ServerRequest('GET', '/?api_token=abc'))
        );

        $this->assertFalse($guard->check());
    }
}
