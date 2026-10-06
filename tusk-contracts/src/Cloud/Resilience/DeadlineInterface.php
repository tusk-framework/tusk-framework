<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

interface DeadlineInterface
{
    public function isExpired(int $nowMilliseconds): bool;

    public function remainingMilliseconds(int $nowMilliseconds): ?int;
}
