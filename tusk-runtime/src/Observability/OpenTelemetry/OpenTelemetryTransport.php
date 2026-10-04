<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use RuntimeException;
use Tusk\Runtime\Observability\ObservabilityConfiguration;

final class OpenTelemetryTransport implements OpenTelemetryTransportInterface
{
    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly MeterInterface $meter,
        private readonly TracerProviderInterface $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
    ) {}

    public static function fromConfiguration(ObservabilityConfiguration $configuration): self
    {
        $endpoint = $configuration->otlpEndpoint();
        if ($endpoint === null) {
            throw new RuntimeException('An OTLP endpoint is required to create the OpenTelemetry transport.');
        }

        $resource = ResourceInfo::create(Attributes::create(array_merge(
            ['service.name' => $configuration->serviceName()],
            $configuration->resource(),
        )));
        $http = new OtlpHttpTransportFactory;
        $traceTransport = $http->create($endpoint, 'application/x-protobuf');
        $traceExporter = new SpanExporter($traceTransport);
        $tracerProvider = (new TracerProviderBuilder)
            ->addSpanProcessor(new SimpleSpanProcessor($traceExporter))
            ->setSampler(new TraceIdRatioBasedSampler($configuration->sampleRatio()))
            ->setResource($resource)
            ->build();

        $metricsEndpoint = preg_replace('~/v1/traces/?$~', '/v1/metrics', $endpoint) ?: $endpoint;
        $metricsTransport = $http->create($metricsEndpoint, 'application/x-protobuf');
        $metricsExporter = new MetricExporter($metricsTransport);
        $meterProvider = (new MeterProviderBuilder)
            ->addReader(new ExportingReader($metricsExporter))
            ->setResource($resource)
            ->build();

        return new self(
            tracer: $tracerProvider->getTracer('tusk.framework'),
            meter: $meterProvider->getMeter('tusk.framework'),
            tracerProvider: $tracerProvider,
            meterProvider: $meterProvider,
        );
    }

    public function startSpan(string $name, array $attributes = []): OpenTelemetrySpanHandleInterface
    {
        $builder = $this->tracer->spanBuilder($name);
        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        return new OpenTelemetrySpanHandle($builder->startSpan());
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->counters[$name] ??= $this->meter->createCounter($name);
        $this->counters[$name]->add($value, $attributes);
    }

    public function observe(string $name, float $value, array $attributes = []): void
    {
        $this->histograms[$name] ??= $this->meter->createHistogram($name, 's');
        $this->histograms[$name]->record($value, $attributes);
    }

    public function flush(): void
    {
        if (! $this->tracerProvider->forceFlush() || ! $this->meterProvider->forceFlush()) {
            throw new RuntimeException('OpenTelemetry flush was not successful.');
        }
    }

    public function shutdown(): void
    {
        if (! $this->tracerProvider->shutdown() || ! $this->meterProvider->shutdown()) {
            throw new RuntimeException('OpenTelemetry shutdown was not successful.');
        }
    }
}
