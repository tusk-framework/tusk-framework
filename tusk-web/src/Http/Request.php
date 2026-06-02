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
}
