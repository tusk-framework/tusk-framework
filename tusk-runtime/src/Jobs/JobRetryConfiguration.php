<?php

namespace Tusk\Runtime\Jobs;

use InvalidArgumentException;

final class JobRetryConfiguration
{
    private function __construct(private readonly int $maxAttempts, private readonly int $delaySeconds) {}

    public static function fromArray(array $retryConfiguration): self
    {
        $maxAttempts = $retryConfiguration['max_attempts'] ?? 3;
        $delaySeconds = $retryConfiguration['delay_seconds'] ?? 1;

        if (! is_int($maxAttempts) || $maxAttempts < 1) {
            throw new InvalidArgumentException('Job retry max_attempts must be a positive integer.');
        }
        if (! is_int($delaySeconds) || $delaySeconds < 0) {
            throw new InvalidArgumentException('Job retry delay_seconds must be a non-negative integer.');
        }

        return new self($maxAttempts, $delaySeconds);
    }

    public function maxAttempts(): int { return $this->maxAttempts; }

    public function delaySeconds(): int { return $this->delaySeconds; }
}
