<?php

declare(strict_types=1);

namespace Tusk\Web\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Wrapper for PSR-7 ServerRequestInterface to provide easier property access.
 */
class Request
{
    public array $query;
    public array|object|null $request;
    public string $method;
    public array $server;

    public function __construct(private ServerRequestInterface $psrRequest)
    {
        $this->query = $psrRequest->getQueryParams();
        $this->request = $psrRequest->getParsedBody();
        $this->method = strtoupper($psrRequest->getMethod());
        $this->server = $psrRequest->getServerParams();
    }

    public function getPsrRequest(): ServerRequestInterface
    {
        return $this->psrRequest;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getParsedBody()
    {
        return $this->request;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $values = $this->psrRequest->getHeader($name);

        if ($values === []) {
            return $default;
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (is_array($this->request) && array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }
}
