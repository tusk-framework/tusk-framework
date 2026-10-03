<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Metrics\MetricsInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerMetrics;

final class RoadRunnerMetricsTest extends TestCase
{
    public function test_it_preserves_metric_values_and_labels(): void
    {
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects(self::once())->method('add')->with('requests_total', 2.0, ['route' => '/users']);
        $metrics->expects(self::once())->method('sub')->with('in_flight', 1.0, ['route' => '/users']);
        $metrics->expects(self::once())->method('observe')->with('latency', 0.25, ['route' => '/users']);
        $metrics->expects(self::once())->method('set')->with('workers', 3.0, ['pool' => 'http']);
        $metrics->expects(self::once())->method('unregister')->with('workers');

        $adapter = new RoadRunnerMetrics($metrics);

        $adapter->increment('requests_total', 2, ['route' => '/users']);
        $adapter->decrement('in_flight', 1, ['route' => '/users']);
        $adapter->observe('latency', 0.25, ['route' => '/users']);
        $adapter->set('workers', 3, ['pool' => 'http']);
        $adapter->unregister('workers');
    }
}
