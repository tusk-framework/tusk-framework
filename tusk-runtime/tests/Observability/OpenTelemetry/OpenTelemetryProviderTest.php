<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability\OpenTelemetry;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Runtime\Observability\OpenTelemetry\OpenTelemetrySpanHandleInterface;
use Tusk\Runtime\Observability\OpenTelemetry\OpenTelemetryProvider;
use Tusk\Runtime\Observability\OpenTelemetry\OpenTelemetryTransportInterface;

final class OpenTelemetryProviderTest extends TestCase
{
    public function test_provider_maps_spans_and_metrics_to_the_telemetry_transport(): void
    {
        $transport = new FakeOpenTelemetryTransport;
        $provider = new OpenTelemetryProvider($transport);

        $span = $provider->startSpan('tusk.http.server', ['http.method' => 'GET']);
        self::assertInstanceOf(SpanInterface::class, $span);
        $span->setAttribute('http.status_code', 200);
        $span->setStatus('ok');
        $span->end();
        $provider->increment('tusk.requests.total', 1, ['route' => '/']);
        $provider->observe('tusk.request.duration', 0.25, ['route' => '/']);

        self::assertSame('tusk.http.server', $transport->spanName);
        self::assertSame(['http.method' => 'GET'], $transport->spanAttributes);
        self::assertSame(['http.status_code' => 200], $transport->handle->attributes);
        self::assertSame(['ok', null], $transport->handle->status);
        self::assertTrue($transport->handle->ended);
        self::assertSame([['tusk.requests.total', 1, ['route' => '/']]], $transport->increments);
        self::assertSame([['tusk.request.duration', 0.25, ['route' => '/']]], $transport->observations);
    }

    public function test_provider_records_exceptions_on_spans(): void
    {
        $transport = new FakeOpenTelemetryTransport;
        $provider = new OpenTelemetryProvider($transport);
        $exception = new RuntimeException('must remain inside telemetry');

        $span = $provider->startSpan('tusk.request');
        $span->recordException($exception, ['category' => 'request.failure']);

        self::assertSame($exception, $transport->handle->exception);
        self::assertSame(['category' => 'request.failure'], $transport->handle->exceptionAttributes);
    }

    public function test_exporter_failures_are_reported_without_escaping_flush_or_shutdown(): void
    {
        $transport = new FakeOpenTelemetryTransport;
        $transport->flushFailure = new RuntimeException('exporter unavailable');
        $transport->shutdownFailure = new RuntimeException('shutdown unavailable');
        $failures = [];
        $provider = new OpenTelemetryProvider($transport, static function (Throwable $exception) use (&$failures): void {
            $failures[] = $exception;
        });

        $provider->flush();
        $provider->shutdown();

        self::assertSame([$transport->flushFailure, $transport->shutdownFailure], $failures);
    }
}

final class FakeOpenTelemetryTransport implements OpenTelemetryTransportInterface
{
    public string $spanName = '';

    /** @var array<string, scalar|null> */
    public array $spanAttributes = [];

    public FakeOpenTelemetrySpanHandle $handle;

    /** @var list<array{string, int|float, array<string, scalar|null>}> */
    public array $increments = [];

    /** @var list<array{string, float, array<string, scalar|null>}> */
    public array $observations = [];

    public ?Throwable $flushFailure = null;

    public ?Throwable $shutdownFailure = null;

    public function __construct()
    {
        $this->handle = new FakeOpenTelemetrySpanHandle;
    }

    public function startSpan(string $name, array $attributes = []): OpenTelemetrySpanHandleInterface
    {
        $this->spanName = $name;
        $this->spanAttributes = $attributes;

        return $this->handle;
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->increments[] = [$name, $value, $attributes];
    }

    public function observe(string $name, float $value, array $attributes = []): void
    {
        $this->observations[] = [$name, $value, $attributes];
    }

    public function flush(): void
    {
        if ($this->flushFailure !== null) {
            throw $this->flushFailure;
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdownFailure !== null) {
            throw $this->shutdownFailure;
        }
    }
}

final class FakeOpenTelemetrySpanHandle implements OpenTelemetrySpanHandleInterface
{
    /** @var array<string, scalar|null> */
    public array $attributes = [];

    /** @var array{string, ?string}|null */
    public ?array $status = null;

    public ?Throwable $exception = null;

    /** @var array<string, scalar|null> */
    public array $exceptionAttributes = [];

    public bool $ended = false;

    public function setAttribute(string $name, string|int|float|bool|null $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function recordException(Throwable $exception, array $attributes = []): void
    {
        $this->exception = $exception;
        $this->exceptionAttributes = $attributes;
    }

    public function setStatus(string $status, ?string $description = null): void
    {
        $this->status = [$status, $description];
    }

    public function end(?float $endTime = null): void
    {
        $this->ended = true;
    }
}
