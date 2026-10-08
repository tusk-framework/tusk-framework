<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Event;

use InvalidArgumentException;

final readonly class FallbackApplied
{
    public function __construct(
        public string $operation,
        public string $failureType,
    ) {
        if (trim($operation) === '') {
            throw new InvalidArgumentException('Operation must not be blank.');
        }

        if (trim($failureType) === '') {
            throw new InvalidArgumentException('Failure type must not be blank.');
        }
    }
}
