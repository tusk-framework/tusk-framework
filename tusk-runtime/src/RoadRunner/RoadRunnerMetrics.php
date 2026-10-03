<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface as RoadRunnerMetricsInterface;
use Tusk\Contracts\Runtime\Capabilities\MetricsInterface;

final class RoadRunnerMetrics implements MetricsInterface
{
    public function __construct(private readonly RoadRunnerMetricsInterface $metrics) {}

    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        $this->metrics->add($name, (float) $value, $labels);
    }

    public function decrement(string $name, int $value = 1, array $labels = []): void
    {
        $this->metrics->sub($name, (float) $value, $labels);
    }

    public function set(string $name, int|float $value, array $labels = []): void
    {
        $this->metrics->set($name, (float) $value, $labels);
    }

    public function observe(string $name, int|float $value, array $labels = []): void
    {
        $this->metrics->observe($name, (float) $value, $labels);
    }

    public function declare(string $name, array $labels = []): void
    {
        $labelNames = array_keys($labels);
        $labelNames = array_map(static fn (mixed $label): string => (string) $label, $labelNames);

        $this->metrics->declare($name, Collector::counter()->withLabels(...$labelNames));
    }

    public function unregister(string $name): void
    {
        $this->metrics->unregister($name);
    }
}
