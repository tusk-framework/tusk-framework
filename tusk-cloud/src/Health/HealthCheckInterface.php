<?php

namespace Tusk\Cloud\Health;

interface HealthCheckInterface
{
    /**
     * Returns the name of the check (e.g., "database", "redis").
     */
    public function getName(): string;

    /**
     * Performs the check.
     * Returns true if healthy, false otherwise.
     * Optionally throws an exception with details on failure.
     */
    public function check(): bool;
}
