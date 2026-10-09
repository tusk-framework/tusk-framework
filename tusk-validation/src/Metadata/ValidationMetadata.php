<?php

namespace Tusk\Validation\Metadata;

final readonly class ValidationMetadata
{
    /** @param array<string, list<object>> $constraints */
    public function __construct(private array $constraints)
    {
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->constraints);
    }

    /** @return list<object> */
    public function constraintsFor(string $field): array
    {
        return $this->constraints[$field] ?? [];
    }
}
