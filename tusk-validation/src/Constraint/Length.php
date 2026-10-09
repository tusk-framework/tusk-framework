<?php

namespace Tusk\Validation\Constraint;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Length
{
    public function __construct(public ?int $min = null, public ?int $max = null)
    {
        if ($min !== null && $min < 0) {
            throw new InvalidArgumentException('Length minimum must be non-negative.');
        }

        if ($max !== null && $max < 0) {
            throw new InvalidArgumentException('Length maximum must be non-negative.');
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('Length minimum must not exceed maximum.');
        }
    }
}
