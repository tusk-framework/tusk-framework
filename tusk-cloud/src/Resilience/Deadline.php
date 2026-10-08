<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\DeadlineInterface;

final readonly class Deadline implements DeadlineInterface
{
    public function __construct(private int $expiresAtMilliseconds)
    {
        if ($expiresAtMilliseconds < 0) {
            throw new InvalidArgumentException('Deadline cannot be negative.');
        }
    }

    public function isExpired(int $nowMilliseconds): bool
    {
        $this->validateNow($nowMilliseconds);

        return $nowMilliseconds >= $this->expiresAtMilliseconds;
    }

    public function remainingMilliseconds(int $nowMilliseconds): int
    {
        $this->validateNow($nowMilliseconds);

        return max(0, $this->expiresAtMilliseconds - $nowMilliseconds);
    }

    private function validateNow(int $nowMilliseconds): void
    {
        if ($nowMilliseconds < 0) {
            throw new InvalidArgumentException('Current time cannot be negative.');
        }
    }
}
