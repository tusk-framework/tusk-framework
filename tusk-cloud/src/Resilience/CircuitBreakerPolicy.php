<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;

final readonly class CircuitBreakerPolicy
{
    private function __construct(
        private int $failureThreshold,
        private int $openDurationMilliseconds,
        private int $halfOpenProbeLimit,
    ) {}

    public static function create(int $failureThreshold = 5, int $openDurationMilliseconds = 10_000, int $halfOpenProbeLimit = 1): self
    {
        if ($failureThreshold < 1 || $openDurationMilliseconds < 1 || $halfOpenProbeLimit < 1) {
            throw new InvalidArgumentException('Circuit threshold, open duration, and probe limit must be positive.');
        }

        return new self($failureThreshold, $openDurationMilliseconds, $halfOpenProbeLimit);
    }

    public function failureThreshold(): int
    {
        return $this->failureThreshold;
    }

    public function openDurationMilliseconds(): int
    {
        return $this->openDurationMilliseconds;
    }

    public function halfOpenProbeLimit(): int
    {
        return $this->halfOpenProbeLimit;
    }
}
