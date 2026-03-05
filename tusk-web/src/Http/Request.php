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

    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $name) {
                return is_array($values) ? implode(', ', $values) : $values;
            }
        }
        return $default;
    }

    public function getHeaders(string $name): array
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $name) {
                return (array)$values;
            }
        }
        return [];
    }

    public static function createFromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $body = file_get_contents('php://input');

        return new self($method, $uri, $headers, $body);
    }
}
