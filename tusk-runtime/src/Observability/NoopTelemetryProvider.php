<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

final class NoopTelemetryProvider implements TelemetryProviderInterface
{
    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new NoopSpan;
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void {}

    public function observe(string $name, float $value, array $attributes = []): void {}

    public function flush(): void {}

    public function shutdown(): void {}
}
