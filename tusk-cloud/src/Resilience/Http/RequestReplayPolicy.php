<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Http;

use InvalidArgumentException;

final readonly class RequestReplayPolicy
{
    /**
     * @param  array<string, true>  $idempotentMethods
     */
    private function __construct(
        private string $idempotencyKeyHeader,
        private array $idempotentMethods,
        private bool $allowBodyReplay,
    ) {}

    /**
     * @param  list<mixed>  $idempotentMethods
     */
    public static function create(
        string $idempotencyKeyHeader = 'Idempotency-Key',
        array $idempotentMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'],
        bool $allowBodyReplay = false,
    ): self {
        $idempotencyKeyHeader = trim($idempotencyKeyHeader);
        if ($idempotencyKeyHeader === '') {
            throw new InvalidArgumentException('Idempotency-key header name cannot be blank.');
        }

        $methods = [];
        foreach ($idempotentMethods as $method) {
            if (! is_string($method) || trim($method) === '') {
                throw new InvalidArgumentException('Idempotent method names cannot be blank.');
            }

            $methods[trim($method)] = true;
        }

        return new self($idempotencyKeyHeader, $methods, $allowBodyReplay);
    }

    public function isIdempotentMethod(string $method): bool
    {
        return isset($this->idempotentMethods[$method]);
    }

    public function idempotencyKeyHeader(): string
    {
        return $this->idempotencyKeyHeader;
    }

    public function allowsBodyReplay(): bool
    {
        return $this->allowBodyReplay;
    }
}
