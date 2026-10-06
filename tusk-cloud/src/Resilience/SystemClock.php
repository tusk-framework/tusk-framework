<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function nowMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Sleep duration cannot be negative.');
        }

        if ($milliseconds > intdiv(PHP_INT_MAX, 1_000)) {
            throw new InvalidArgumentException('Sleep duration is too large.');
        }

        usleep($milliseconds * 1_000);
    }
}
