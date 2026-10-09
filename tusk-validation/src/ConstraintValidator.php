<?php

namespace Tusk\Validation;

use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;
use Tusk\Validation\Metadata\ValidationMetadata;

final class ConstraintValidator
{
    /** @return list<Violation> */
    public function validate(object $value, ValidationMetadata $metadata): array
    {
        $violations = [];
        foreach ($metadata->fields() as $field) {
            $fieldValue = $value->$field;
            foreach ($metadata->constraintsFor($field) as $constraint) {
                if ($constraint instanceof NotBlank && ($fieldValue === null || (is_string($fieldValue) && preg_match('/^\\s*$/u', $fieldValue) === 1))) {
                    $violations[] = new Violation($field, 'not_blank', 'This value should not be blank.');
                } elseif ($constraint instanceof Email && $fieldValue !== null && $fieldValue !== '' && filter_var($fieldValue, FILTER_VALIDATE_EMAIL) === false) {
                    $violations[] = new Violation($field, 'email', 'This value is not a valid email address.');
                } elseif ($constraint instanceof Length && $fieldValue !== null) {
                    $length = preg_match_all('/./us', $fieldValue);
                    if ($length === false) {
                        $violations[] = new Violation($field, 'length.invalid_encoding', 'This value is not valid UTF-8.');
                    } elseif ($constraint->min !== null && $length < $constraint->min) {
                        $violations[] = new Violation($field, 'length.min', 'This value is too short.');
                    } elseif ($constraint->max !== null && $length > $constraint->max) {
                        $violations[] = new Violation($field, 'length.max', 'This value is too long.');
                    }
                }
            }
        }

        return $violations;
    }
}
