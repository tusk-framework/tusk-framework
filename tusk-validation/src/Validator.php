<?php

namespace Tusk\Validation;

use Tusk\Validation\Metadata\ValidationMetadata;

final class Validator implements ValidatorInterface
{
    public function __construct(private readonly ConstraintValidator $constraintValidator)
    {
    }

    public function validate(
        object $value,
        ValidationMetadata $metadata,
        iterable $customValidators = [],
        array $constructorValues = [],
    ): ValidationResult
    {
        $violations = $this->constraintValidator->validate($metadata, $constructorValues);
        foreach ($customValidators as $customValidator) {
            if (!$customValidator instanceof CustomValidatorInterface) {
                throw new \InvalidArgumentException('Custom validators must implement CustomValidatorInterface.');
            }
            foreach ($customValidator->validate($value) as $violation) {
                if (!$violation instanceof Violation) {
                    throw new \InvalidArgumentException('Custom validators must return Violation instances.');
                }
                $violations[] = $violation;
            }
        }

        return new ValidationResult($violations);
    }
}
