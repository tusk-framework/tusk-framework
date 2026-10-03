<?php

namespace Tusk\Web\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Web\Http\Request;

class RequestTest extends TestCase
{
    public function test_headers_are_case_insensitive_and_input_prefers_parsed_body(): void
    {
        $psrRequest = new ServerRequest('POST', '/?page=2', ['X-Trace' => 'abc']);
        $psrRequest = $psrRequest->withParsedBody(['page' => 3, 'name' => 'Ada']);

        $request = new Request($psrRequest);

        $this->assertSame('abc', $request->header('x-trace'));
        $this->assertSame(3, $request->get('page'));
        $this->assertSame('Ada', $request->get('name'));
        $this->assertSame('fallback', $request->get('missing', 'fallback'));
    }
}
