<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

use InvalidArgumentException;

final readonly class OperationContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        private string $operation,
        private ?DeadlineInterface $deadline,
        private bool $retryAllowed,
        private array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function create(string $operation, ?DeadlineInterface $deadline = null, bool $retryAllowed = false, array $metadata = []): self
    {
        $operation = trim($operation);

        if ($operation === '') {
            throw new InvalidArgumentException('Operation name cannot be blank.');
        }

        return new self($operation, $deadline, $retryAllowed, $metadata);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function deadline(): ?DeadlineInterface
    {
        return $this->deadline;
    }

    public function retryAllowed(): bool
    {
        return $this->retryAllowed;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
