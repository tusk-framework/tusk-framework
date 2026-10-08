<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    private function __construct(
        private int $capacity,
        private float $refillPerSecond,
        private int $maxWaitMilliseconds,
    ) {}

    public static function create(int $capacity = 1, float $refillPerSecond = 1.0, int $maxWaitMilliseconds = 0): self
    {
        if ($capacity < 1 || $refillPerSecond <= 0 || ! is_finite($refillPerSecond) || $maxWaitMilliseconds < 0) {
            throw new InvalidArgumentException('Rate capacity and finite refill rate must be positive; maximum wait must be non-negative.');
        }

        return new self($capacity, $refillPerSecond, $maxWaitMilliseconds);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function refillPerSecond(): float
    {
        return $this->refillPerSecond;
    }

    public function refillPerMillisecond(): float
    {
        return $this->refillPerSecond / 1000;
    }

    public function maxWaitMilliseconds(): int
    {
        return $this->maxWaitMilliseconds;
    }
}
