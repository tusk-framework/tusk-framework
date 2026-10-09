<?php

namespace Tusk\Validation;

use Closure;
use LogicException;

final class CustomValidatorRegistry
{
    /** @var array<string, list<Closure(): mixed>> */
    private array $validators = [];

    private bool $sealed = false;

    public function register(string $dtoClass, CustomValidatorInterface $validator): void
    {
        $this->registerFactory($dtoClass, static fn (): CustomValidatorInterface => $validator);
    }

    /** @param Closure(): mixed $factory */
    public function registerFactory(string $dtoClass, Closure $factory): void
    {
        if ($this->sealed) {
            throw new LogicException('Custom validator registry is sealed.');
        }

        $this->validators[$dtoClass][] = $factory;
    }

    /** @return list<CustomValidatorInterface> */
    public function for(string $dtoClass): array
    {
        $validators = [];
        foreach ($this->validators[$dtoClass] ?? [] as $factory) {
            $validator = $factory();
            if (! $validator instanceof CustomValidatorInterface) {
                throw new LogicException("Custom validator factory for {$dtoClass} returned an invalid service.");
            }
            $validators[] = $validator;
        }

        return $validators;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }
}
