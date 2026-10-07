<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Backoff;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\BackoffStrategyInterface;

final readonly class FixedBackoff implements BackoffStrategyInterface
{
    private function __construct(private int $delayMilliseconds, private int $maxDelayMilliseconds) {}

    public static function create(int $delayMilliseconds = 0, int $maxDelayMilliseconds = PHP_INT_MAX): self
    {
        if ($delayMilliseconds < 0 || $maxDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Backoff delays must be non-negative.');
        }

        return new self($delayMilliseconds, $maxDelayMilliseconds);
    }

    public function delayMilliseconds(int $retryNumber, int $previousDelayMilliseconds): int
    {
        if ($retryNumber < 1 || $previousDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Retry number must be positive and previous delay non-negative.');
        }

        return min($this->delayMilliseconds, $this->maxDelayMilliseconds);
    }
}
