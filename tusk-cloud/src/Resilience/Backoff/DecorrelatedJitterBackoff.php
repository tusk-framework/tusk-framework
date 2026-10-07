<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Backoff;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\BackoffStrategyInterface;
use Tusk\Contracts\Cloud\Resilience\RandomSourceInterface;

final readonly class DecorrelatedJitterBackoff implements BackoffStrategyInterface
{
    private function __construct(
        private RandomSourceInterface $randomSource,
        private int $baseDelayMilliseconds,
        private int $maxDelayMilliseconds,
    ) {}

    public static function create(RandomSourceInterface $randomSource, int $baseDelayMilliseconds = 100, int $maxDelayMilliseconds = 30_000): self
    {
        if ($baseDelayMilliseconds < 0 || $maxDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Backoff delays must be non-negative.');
        }

        return new self($randomSource, $baseDelayMilliseconds, $maxDelayMilliseconds);
    }

    public function delayMilliseconds(int $retryNumber, int $previousDelayMilliseconds): int
    {
        if ($retryNumber < 1 || $previousDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Retry number must be positive and previous delay non-negative.');
        }

        $minimum = min($this->baseDelayMilliseconds, $this->maxDelayMilliseconds);
        $previous = $previousDelayMilliseconds > 0 ? $previousDelayMilliseconds : $this->baseDelayMilliseconds;
        $tripled = $previous > intdiv($this->maxDelayMilliseconds, 3)
            ? $this->maxDelayMilliseconds
            : $previous * 3;
        $maximum = max($minimum, $tripled);
        $delay = $this->randomSource->nextInt($minimum, $maximum);

        if ($delay < $minimum || $delay > $maximum) {
            throw new InvalidArgumentException('Random source returned a value outside the requested range.');
        }

        return $delay;
    }
}
