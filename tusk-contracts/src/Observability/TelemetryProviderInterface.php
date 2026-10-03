<?php

declare(strict_types=1);

namespace Tusk\Contracts\Observability;

interface TelemetryProviderInterface
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function startSpan(string $name, array $attributes = []): SpanInterface;

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
