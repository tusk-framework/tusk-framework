<?php

namespace Tusk\Cloud\Resilience;

use Tusk\Cloud\Resilience\Exception\CircuitOpenException;

class CircuitBreaker implements CircuitBreakerInterface
{
    private State $state = State::CLOSED;
    private int $failureCount = 0;
    private ?float $lastFailureTime = null;

    public function __construct(
        private int $failureThreshold = 5,
        private float $resetTimeout = 10.0 // Seconds before Half-Open
    ) {
    }

    public function execute(callable $action, ?callable $fallback = null): mixed
    {
        if ($this->state === State::OPEN) {
            if ($this->shouldAttemptReset()) {
                $this->transitionTo(State::HALF_OPEN);
            } else {
                return $this->handleFailure(new CircuitOpenException(), $fallback);
            }
        }

        try {
            $result = $action();

            if ($this->state === State::HALF_OPEN) {
                $this->reset();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            return $this->handleFailure($e, $fallback);
        }
    }

    public function getState(): State
    {
        return $this->state;
    }

    private function block(): void
    {
        $this->state = State::OPEN;
        $this->lastFailureTime = microtime(true);
    }

    private function reset(): void
    {
        $this->state = State::CLOSED;
        $this->failureCount = 0;
        $this->lastFailureTime = null;
    }

    private function transitionTo(State $state): void
    {
        $this->state = $state;
    }

    private function recordFailure(): void
    {
        $this->failureCount++;
        if ($this->failureCount >= $this->failureThreshold) {
            $this->block();
        }
    }

    private function shouldAttemptReset(): bool
    {
        if ($this->lastFailureTime === null) {
            return false;
        }
        return (microtime(true) - $this->lastFailureTime) > $this->resetTimeout;
    }

    private function handleFailure(\Throwable $e, ?callable $fallback): mixed
    {
        if ($fallback) {
            return $fallback($e);
        }
        throw $e;
    }
}
