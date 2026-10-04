<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Observability\NoopTelemetryProvider;
use Tusk\Runtime\Observability\ObservabilityConfiguration;
use Tusk\Runtime\Observability\ObservabilityProviderFactory;

final class ObservabilityConfigurationTest extends TestCase
{
    public function test_defaults_disable_telemetry_and_enable_safe_diagnostics(): void
    {
        $configuration = ObservabilityConfiguration::fromArray([]);

        self::assertFalse($configuration->enabled());
        self::assertSame('none', $configuration->exporter());
        self::assertSame('tusk-application', $configuration->serviceName());
        self::assertSame(1.0, $configuration->sampleRatio());
        self::assertTrue($configuration->diagnosticsEnabled());
        self::assertInstanceOf(NoopTelemetryProvider::class, ObservabilityProviderFactory::create($configuration));
    }

    public function test_it_accepts_valid_otlp_http_configuration(): void
    {
        $configuration = ObservabilityConfiguration::fromArray([
            'enabled' => true,
            'service_name' => 'orders',
            'exporter' => 'otlp',
            'otlp' => ['endpoint' => 'https://collector.example/v1/traces'],
            'sample_ratio' => 0.25,
            'resource' => ['deployment.environment' => 'test'],
            'diagnostics' => false,
        ]);

        self::assertTrue($configuration->enabled());
        self::assertSame('orders', $configuration->serviceName());
        self::assertSame('otlp', $configuration->exporter());
        self::assertSame('https://collector.example/v1/traces', $configuration->otlpEndpoint());
        self::assertSame(0.25, $configuration->sampleRatio());
        self::assertSame(['deployment.environment' => 'test'], $configuration->resource());
        self::assertFalse($configuration->diagnosticsEnabled());
    }

    public function test_invalid_otlp_configuration_fails_before_runtime_start(): void
    {
        foreach ([
            ['exporter' => 'unknown'],
            ['enabled' => true, 'exporter' => 'otlp'],
            ['enabled' => true, 'exporter' => 'otlp', 'otlp' => ['endpoint' => 'file:///tmp/otel']],
            ['sample_ratio' => -0.1],
            ['sample_ratio' => 1.1],
            ['resource' => ['unsafe' => ['nested']]],
        ] as $config) {
            try {
                ObservabilityConfiguration::fromArray($config);
                self::fail('Expected invalid observability configuration to throw.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
