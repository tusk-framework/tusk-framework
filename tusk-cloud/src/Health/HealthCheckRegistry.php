<?php

namespace Tusk\Cloud\Health;

class HealthCheckRegistry
{
    /** @var HealthCheckInterface[] */
    private array $checks = [];

    public function register(HealthCheckInterface $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    public function runChecks(): array
    {
        $results = [];
        $globalStatus = 'UP';

        foreach ($this->checks as $name => $check) {
            try {
                $isHealthy = $check->check();
                $results[$name] = $isHealthy ? 'UP' : 'DOWN';
                if (!$isHealthy) {
                    $globalStatus = 'DOWN';
                }
            } catch (\Throwable $e) {
                $results[$name] = 'DOWN';
                $globalStatus = 'DOWN';
            }
        }

        return [
            'status' => $globalStatus,
            'checks' => $results
        ];
    }
}
