<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Event;

use InvalidArgumentException;

final readonly class RetryScheduled
{
    public function __construct(
        public string $operation,
        public int $failedAttempt,
        public int $delayMilliseconds,
        public string $failureType,
    ) {
        if (trim($operation) === '') {
            throw new InvalidArgumentException('Operation must not be blank.');
        }

        if ($failedAttempt < 1) {
            throw new InvalidArgumentException('Failed attempt must be at least 1.');
        }

        if ($delayMilliseconds < 0) {
            throw new InvalidArgumentException('Delay must not be negative.');
        }

        if (trim($failureType) === '') {
            throw new InvalidArgumentException('Failure type must not be blank.');
        }
    }
}
