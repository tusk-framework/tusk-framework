<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;

final readonly class BulkheadPolicy
{
    private function __construct(private int $maxConcurrent, private int $maxQueued) {}

    public static function create(int $maxConcurrent = 1, int $maxQueued = 0): self
    {
        if ($maxConcurrent < 1 || $maxQueued < 0) {
            throw new InvalidArgumentException('Bulkhead concurrency must be positive and queue size non-negative.');
        }

        return new self($maxConcurrent, $maxQueued);
    }

    public function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    public function maxQueued(): int
    {
        return $this->maxQueued;
    }
}
