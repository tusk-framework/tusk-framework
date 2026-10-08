<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Testing;

use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(private int $nowMilliseconds = 0)
    {
        if ($nowMilliseconds < 0) {
            throw new InvalidArgumentException('Initial time cannot be negative.');
        }
    }

    public function nowMilliseconds(): int
    {
        return $this->nowMilliseconds;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0 || $milliseconds > PHP_INT_MAX - $this->nowMilliseconds) {
            throw new InvalidArgumentException('Sleep duration must be non-negative and cannot overflow the clock.');
        }

        $this->nowMilliseconds += $milliseconds;
    }
}
