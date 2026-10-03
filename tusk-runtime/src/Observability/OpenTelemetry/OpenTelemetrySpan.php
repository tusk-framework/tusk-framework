<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

use Throwable;
use Tusk\Contracts\Observability\SpanInterface;

final class OpenTelemetrySpan implements SpanInterface
{
    public function __construct(private readonly OpenTelemetrySpanHandleInterface $handle) {}

    public function setAttribute(string $name, string|int|float|bool|null $value): void
    {
        $this->handle->setAttribute($name, $value);
    }

    public function recordException(Throwable $exception, array $attributes = []): void
    {
        $this->handle->recordException($exception, $attributes);
    }

    public function setStatus(string $status, ?string $description = null): void
    {
        $this->handle->setStatus($status, $description);
    }

    public function end(?float $endTime = null): void
    {
        $this->handle->end($endTime);
    }
}
