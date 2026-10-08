<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

use InvalidArgumentException;
use ReflectionReference;

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
        private ?CancellationTokenInterface $cancellationToken,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function create(
        string $operation,
        ?DeadlineInterface $deadline = null,
        bool $retryAllowed = false,
        array $metadata = [],
        ?CancellationTokenInterface $cancellationToken = null,
    ): self {
        $operation = trim($operation);

        if ($operation === '') {
            throw new InvalidArgumentException('Operation name cannot be blank.');
        }

        $activeReferences = [];

        return new self($operation, $deadline, $retryAllowed, self::snapshotArray($metadata, $activeReferences), $cancellationToken);
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

    public function cancellationToken(): ?CancellationTokenInterface
    {
        return $this->cancellationToken;
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancellationToken?->isCancellationRequested() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<string, true>  $activeReferences
     * @return array<array-key, mixed>
     */
    private static function snapshotArray(array $values, array &$activeReferences): array
    {
        $snapshot = [];

        foreach ($values as $key => $value) {
            $reference = ReflectionReference::fromArrayElement($values, $key);
            $referenceId = $reference?->getId();

            if ($referenceId !== null && isset($activeReferences[$referenceId])) {
                throw new InvalidArgumentException('Metadata cannot contain recursive references.');
            }

            if (is_array($value)) {
                if ($referenceId !== null) {
                    $activeReferences[$referenceId] = true;
                }

                $snapshot[$key] = self::snapshotArray($value, $activeReferences);

                if ($referenceId !== null) {
                    unset($activeReferences[$referenceId]);
                }

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Metadata may contain only scalars, null, and arrays.');
            }

            $snapshot[$key] = $value;
        }

        return $snapshot;
    }
}
