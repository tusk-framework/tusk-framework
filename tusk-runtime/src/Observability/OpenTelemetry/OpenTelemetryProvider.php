<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability\OpenTelemetry;

use Throwable;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

final class OpenTelemetryProvider implements TelemetryProviderInterface
{
    /**
     * @param  callable(Throwable): void|null  $failureRecorder
     */
    public function __construct(
        private readonly OpenTelemetryTransportInterface $transport,
        private readonly mixed $failureRecorder = null,
    ) {}

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return new OpenTelemetrySpan($this->transport->startSpan($name, $attributes));
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        try {
            $this->transport->increment($name, $value, $attributes);
        } catch (Throwable $exception) {
            $this->recordFailure($exception);
        }
    }

    public function observe(string $name, float $value, array $attributes = []): void
    {
        try {
            $this->transport->observe($name, $value, $attributes);
        } catch (Throwable $exception) {
            $this->recordFailure($exception);
        }
    }

    public function flush(): void
    {
        try {
            $this->transport->flush();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);
        }
    }

    public function shutdown(): void
    {
        try {
            $this->transport->shutdown();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);
        }
    }

    private function recordFailure(Throwable $exception): void
    {
        if (is_callable($this->failureRecorder)) {
            ($this->failureRecorder)($exception);
        }
    }
}
