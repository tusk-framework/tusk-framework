<?php

namespace Tusk\Cloud\Resilience;

interface CircuitBreakerInterface
{
    /**
     * Executes the given callback protected by the circuit breaker.
     *
     * @param callable $action The operation to execute
     * @param callable|null $fallback Function to call if the operation fails or circuit is open
     * @return mixed
     */
    public function execute(callable $action, ?callable $fallback = null): mixed;

    /**
     * Returns the current state of the circuit.
     */
    public function getState(): State;
}
