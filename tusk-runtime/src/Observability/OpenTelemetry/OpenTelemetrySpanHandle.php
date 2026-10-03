<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface as NativeSpanInterface;
use Throwable;

final class OpenTelemetrySpanHandle implements OpenTelemetrySpanHandleInterface
{
    public function __construct(private readonly NativeSpanInterface $span) {}

    public function setAttribute(string $name, string|int|float|bool|null $value): void
    {
        $this->span->setAttribute($name, $value);
    }

    public function recordException(Throwable $exception, array $attributes = []): void
    {
        $this->span->recordException($exception, $attributes);
    }

    public function setStatus(string $status, ?string $description = null): void
    {
        $this->span->setStatus($status, $description);
    }

    public function end(?float $endTime = null): void
    {
        $this->span->end($endTime === null ? null : (int) ($endTime * 1_000_000_000));
    }
}
