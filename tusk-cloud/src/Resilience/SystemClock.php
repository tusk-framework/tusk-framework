<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Closure;
use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;

final class SystemClock implements ClockInterface
{
    /** @var Closure(): (int|float) */
    private Closure $readNanoseconds;

    private int|float $originNanoseconds;

    /**
     * @param  (Closure(): (int|float))|null  $readNanoseconds
     */
    public function __construct(?Closure $readNanoseconds = null)
    {
        $this->readNanoseconds = $readNanoseconds ?? static fn (): int|float => hrtime(true);
        $this->originNanoseconds = ($this->readNanoseconds)();
    }

    public function nowMilliseconds(): int
    {
        $elapsedNanoseconds = (float) ($this->readNanoseconds)() - (float) $this->originNanoseconds;

        if ($elapsedNanoseconds <= 0.0) {
            return 0;
        }

        $elapsedMilliseconds = $elapsedNanoseconds / 1_000_000;

        if ($elapsedMilliseconds >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return (int) $elapsedMilliseconds;
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
