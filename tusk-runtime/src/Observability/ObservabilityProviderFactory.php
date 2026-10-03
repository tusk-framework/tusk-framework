<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use Throwable;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Runtime\Observability\OpenTelemetry\OpenTelemetryProvider;
use Tusk\Runtime\Observability\OpenTelemetry\OpenTelemetryTransport;

final class ObservabilityProviderFactory
{
    /**
     * @param  callable(Throwable): void|null  $failureRecorder
     */
    public static function create(ObservabilityConfiguration $configuration, mixed $failureRecorder = null): TelemetryProviderInterface
    {
        if (! $configuration->enabled() || $configuration->exporter() === 'none') {
            return new NoopTelemetryProvider;
        }

        return new OpenTelemetryProvider(
            OpenTelemetryTransport::fromConfiguration($configuration),
            $failureRecorder,
        );
    }
}
