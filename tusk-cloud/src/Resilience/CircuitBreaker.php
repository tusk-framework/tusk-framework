<?php

namespace Tusk\Cloud\Resilience;

use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

class CircuitBreaker implements CircuitBreakerInterface
{
    private string $key;

    public function __construct(
        private StateStoreInterface $store,
        private string $name = 'default',
        private int $failureThreshold = 5,
        private float $resetTimeout = 10.0 // Seconds before Half-Open
    ) {
        $this->key = "cb:{$name}";
    }

    public function execute(callable $action, ?callable $fallback = null): mixed
    {
        $data = $this->loadState();
        $state = $data['state'];

        if ($state === State::OPEN) {
            if ($this->shouldAttemptReset($data['lastFailureTime'])) {
                $this->transitionTo(State::HALF_OPEN);
                $data = $this->loadState(); // Refresh after transition
                $state = $data['state'];
            } else {
                return $this->handleFailure(new CircuitOpenException(), $fallback);
            }
        }

        try {
            $result = $action();

            if ($state === State::HALF_OPEN) {
                $this->reset();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            return $this->handleFailure($e, $fallback);
        }
    }

    private function loadState(): array
    {
        return $this->store->get($this->key) ?: [
            'state' => State::CLOSED,
            'failureCount' => 0,
            'lastFailureTime' => null
        ];
    }

    private function saveState(array $data): void
    {
        $this->store->set($this->key, $data);
    }

    public function getState(): State
    {
        return $this->loadState()['state'];
    }

    private function block(): void
    {
        $this->saveState([
            'state' => State::OPEN,
            'failureCount' => $this->failureThreshold,
            'lastFailureTime' => microtime(true)
        ]);
    }

    private function reset(): void
    {
        $this->saveState([
            'state' => State::CLOSED,
            'failureCount' => 0,
            'lastFailureTime' => null
        ]);
    }

    private function transitionTo(State $state): void
    {
        $data = $this->loadState();
        $data['state'] = $state;
        $this->saveState($data);
    }

    private function recordFailure(): void
    {
        $data = $this->loadState();
        $data['failureCount']++;
        if ($data['failureCount'] >= $this->failureThreshold) {
            $this->block();
        } else {
            $this->saveState($data);
        }
    }

    private function shouldAttemptReset(?float $lastFailureTime): bool
    {
        if ($lastFailureTime === null) {
            return false;
        }
        return (microtime(true) - $lastFailureTime) > $this->resetTimeout;
    }

    private function handleFailure(\Throwable $e, ?callable $fallback): mixed
    {
        if ($fallback) {
            return $fallback($e);
        }
        throw $e;
    }
}
