<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

use Throwable;

interface OpenTelemetrySpanHandleInterface
{
    public function setAttribute(string $name, string|int|float|bool|null $value): void;

    public function recordException(Throwable $exception, array $attributes = []): void;

    public function setStatus(string $status, ?string $description = null): void;

    public function end(?float $endTime = null): void;
}
