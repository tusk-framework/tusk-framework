<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

interface OpenTelemetryTransportInterface
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function startSpan(string $name, array $attributes = []): OpenTelemetrySpanHandleInterface;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function observe(string $name, float $value, array $attributes = []): void;

    public function flush(): void;

    public function shutdown(): void;
}
