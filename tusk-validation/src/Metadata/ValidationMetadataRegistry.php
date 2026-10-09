<?php

namespace Tusk\Validation\Metadata;

use LogicException;

final class ValidationMetadataRegistry
{
    /** @var array<string, ValidationMetadata> */
    private array $metadata = [];

    private bool $sealed = false;

    public function register(string $dtoClass, ValidationMetadata $metadata): void
    {
        if ($this->sealed) {
            throw new LogicException('Validation metadata registry is sealed.');
        }
        if (isset($this->metadata[$dtoClass])) {
            throw new LogicException("Validation metadata already registered for {$dtoClass}.");
        }

        $this->metadata[$dtoClass] = $metadata;
    }

    public function metadataFor(string $dtoClass): ValidationMetadata
    {
        return $this->metadata[$dtoClass]
            ?? throw new LogicException("Validation metadata not registered for {$dtoClass}.");
    }

    public function seal(): void
    {
        $this->sealed = true;
    }
}
