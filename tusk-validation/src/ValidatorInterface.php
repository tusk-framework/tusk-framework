<?php

namespace Tusk\Validation;

use Tusk\Validation\Metadata\ValidationMetadata;

interface ValidatorInterface
{
    /** @param array<string, mixed> $constructorValues Values used to hydrate the DTO, including applied defaults.
     *  @param iterable<CustomValidatorInterface> $customValidators
     */
    public function validate(
        object $value,
        ValidationMetadata $metadata,
        array $constructorValues,
        iterable $customValidators = [],
    ): ValidationResult;
}
