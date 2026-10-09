<?php

namespace Tusk\Validation;

use Tusk\Validation\Metadata\ValidationMetadata;

interface ValidatorInterface
{
    /** @param iterable<CustomValidatorInterface> $customValidators
     *  @param array<string, mixed> $constructorValues Values used to hydrate the DTO, including applied defaults.
     */
    public function validate(
        object $value,
        ValidationMetadata $metadata,
        iterable $customValidators = [],
        array $constructorValues = [],
    ): ValidationResult;
}
