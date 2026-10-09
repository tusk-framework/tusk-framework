<?php

namespace Tusk\Validation;

final readonly class Violation
{
    public function __construct(
        private string $field,
        private string $code,
        private string $message,
    ) {
    }

    public function field(): string
    {
        return $this->field;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }
}
