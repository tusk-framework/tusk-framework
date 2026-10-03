<?php

declare(strict_types=1);

namespace Tusk\Web\Http;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    private Psr7Response $response;

    public function __construct(int $status = 200, array $headers = [], mixed $body = null, string $version = '1.1')
    {
        $this->response = new Psr7Response($status, $headers, $body, $version);
    }

    public static function html(string $content, int $status = 200): ResponseInterface
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $content);
    }

    public static function json(array|object $data, int $status = 200): ResponseInterface
    {
        return new self($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new self($status, ['Location' => $url]);
    }

    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withProtocolVersion($version);

        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withHeader($name, $value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withAddedHeader($name, $value);

        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withoutHeader($name);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withBody($body);

        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withStatus($code, $reasonPhrase);

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }
}
