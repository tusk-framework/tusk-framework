<?php

namespace Tusk\Validation;

interface CustomValidatorInterface
{
    /** @return iterable<Violation> */
    public function validate(object $value): iterable;
}
