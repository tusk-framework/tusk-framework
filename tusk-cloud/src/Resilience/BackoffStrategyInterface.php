<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

interface BackoffStrategyInterface
{
    /** The retry number is one-based; the first retry follows attempt one. */
    public function delayMilliseconds(int $retryNumber, int $previousDelayMilliseconds): int;
}
