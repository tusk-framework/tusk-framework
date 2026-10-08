<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

final readonly class FailureDecision
{
    private function __construct(
        private bool $retryable,
        private bool $circuitFailure,
    ) {}

    public static function retryable(bool $circuitFailure = true): self
    {
        return new self(true, $circuitFailure);
    }

    public static function terminal(bool $circuitFailure = true): self
    {
        return new self(false, $circuitFailure);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function countsAsCircuitFailure(): bool
    {
        return $this->circuitFailure;
    }
}
