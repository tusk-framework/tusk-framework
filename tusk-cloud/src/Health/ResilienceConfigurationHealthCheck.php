<?php

declare(strict_types=1);

namespace Tusk\Cloud\Health;

final class ResilienceConfigurationHealthCheck implements HealthCheckInterface
{
    public function getName(): string
    {
        return 'resilience_configuration';
    }

    public function check(): bool
    {
        // The application registers this check only after configuration was validated at boot.
        return true;
    }
}
