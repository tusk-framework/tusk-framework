<?php

namespace Tusk\Web\Http;

class Request
{
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = [],
        public string $body = ''
    ) {
    }

    public static function createFromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $headers = getallheaders();
        $body = file_get_contents('php://input');

        return new self($method, $uri, $headers, $body);
    }
}
