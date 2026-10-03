<?php

declare(strict_types=1);

namespace Tusk\Runtime\Modules;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Runtime\Observability\ObservabilityConfiguration;
use Tusk\Runtime\Observability\ObservabilityProviderFactory;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Runtime\Observability\WorkerDiagnosticsCollector;

final class RuntimeObservabilityModule implements RuntimeModuleInterface
{
    public function __construct(private readonly ObservabilityConfiguration $configuration) {}

    public function name(): string
    {
        return 'runtime.observability';
    }

    public function register(ContainerInterface $container): void
    {
        $collector = new WorkerDiagnosticsCollector;
        $provider = ObservabilityProviderFactory::create(
            $this->configuration,
            static fn (\Throwable $exception): void => $collector->telemetryFailure($exception),
        );

        $container->instance(TelemetryProviderInterface::class, $provider);
        $container->instance(WorkerDiagnosticsInterface::class, $collector);
        $container->instance(WorkerDiagnosticsCollector::class, $collector);
        $container->instance(RuntimeObservability::class, new RuntimeObservability($provider, $collector));
    }

    public function start(): void {}

    public function stop(): void {}
}
