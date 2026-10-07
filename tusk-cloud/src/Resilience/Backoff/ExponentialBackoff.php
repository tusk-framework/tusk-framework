<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Backoff;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\BackoffStrategyInterface;

final readonly class ExponentialBackoff implements BackoffStrategyInterface
{
    private function __construct(private int $baseDelayMilliseconds, private int $maxDelayMilliseconds) {}

    public static function create(int $baseDelayMilliseconds = 100, int $maxDelayMilliseconds = 30_000): self
    {
        if ($baseDelayMilliseconds < 0 || $maxDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Backoff delays must be non-negative.');
        }

        return new self($baseDelayMilliseconds, $maxDelayMilliseconds);
    }

    public function delayMilliseconds(int $retryNumber, int $previousDelayMilliseconds): int
    {
        if ($retryNumber < 1 || $previousDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Retry number must be positive and previous delay non-negative.');
        }

        $delay = min($this->baseDelayMilliseconds, $this->maxDelayMilliseconds);
        if ($delay === 0) {
            return 0;
        }

        for ($number = 1; $number < $retryNumber && $delay < $this->maxDelayMilliseconds; $number++) {
            $delay = $delay >= intdiv($this->maxDelayMilliseconds, 2)
                ? $this->maxDelayMilliseconds
                : $delay * 2;
        }

        return $delay;
    }
}
