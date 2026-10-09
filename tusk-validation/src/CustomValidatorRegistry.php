<?php

namespace Tusk\Validation;

use LogicException;

final class CustomValidatorRegistry
{
    /** @var array<string, list<CustomValidatorInterface>> */
    private array $validators = [];

    private bool $sealed = false;

    public function register(string $dtoClass, CustomValidatorInterface $validator): void
    {
        if ($this->sealed) {
            throw new LogicException('Custom validator registry is sealed.');
        }

        $this->validators[$dtoClass][] = $validator;
    }

    /** @return list<CustomValidatorInterface> */
    public function for(string $dtoClass): array
    {
        return $this->validators[$dtoClass] ?? [];
    }

    public function seal(): void
    {
        $this->sealed = true;
    }
}
