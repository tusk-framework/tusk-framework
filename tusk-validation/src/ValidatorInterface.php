<?php

namespace Tusk\Validation;

use Tusk\Validation\Metadata\ValidationMetadata;

interface ValidatorInterface
{
    /** @param iterable<CustomValidatorInterface> $customValidators */
    public function validate(
        object $value,
        ValidationMetadata $metadata,
        iterable $customValidators = [],
    ): ValidationResult;
}
