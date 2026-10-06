<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

interface ClockInterface
{
    public function nowMilliseconds(): int;

    public function sleepMilliseconds(int $milliseconds): void;
}
