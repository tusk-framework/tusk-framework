<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability;

use DateTimeImmutable;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Runtime\Observability\DiagnosticsClockInterface;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Runtime\Observability\WorkerDiagnosticsCollector;

final class RuntimeObservabilityTest extends TestCase
{
    public function test_it_records_application_worker_request_and_job_boundaries(): void
    {
        $clock = new RuntimeObservabilityClock;
        $collector = new WorkerDiagnosticsCollector($clock);
        $provider = new RecordingTelemetryProvider;
        $observability = new RuntimeObservability($provider, $collector, $clock, 'roadrunner', 'worker-1');

        $observability->applicationStarted();
        $observability->workerStarted();
        $observability->requestStarted(new ServerRequest('GET', '/orders'));
        $observability->requestFinished(new Response(201), null);
        $observability->jobStarted('emails', 'job-1');
        $observability->jobFinished(true);
        $observability->workerStopped();
        $observability->applicationStopped();

        self::assertSame([
            'tusk.application.start',
            'tusk.worker.start',
            'tusk.http.server',
            'tusk.job.process',
            'tusk.worker.stop',
            'tusk.application.stop',
        ], array_map(static fn (RecordingSpan $span): string => $span->name, $provider->spans));
        self::assertSame(201, $provider->spans[2]->attributes['http.status_code']);
        self::assertSame('/orders', $provider->spans[2]->attributes['url.path']);
        self::assertTrue($provider->spans[2]->ended);
        self::assertSame(1, $collector->snapshot()->requestsTotal);
        self::assertSame(1, $collector->snapshot()->jobsTotal);
    }

    public function test_request_failure_is_recorded_without_replacing_the_original_exception(): void
    {
        $clock = new RuntimeObservabilityClock;
        $collector = new WorkerDiagnosticsCollector($clock);
        $provider = new RecordingTelemetryProvider;
        $observability = new RuntimeObservability($provider, $collector, $clock, 'roadrunner', 'worker-1');
        $observability->applicationStarted();
        $observability->workerStarted();
        $observability->requestStarted(new ServerRequest('POST', '/orders'));
        $exception = new RuntimeException('handler failed');

        $observability->requestFinished(null, $exception);

        self::assertSame($exception, $provider->spans[2]->exception);
        self::assertSame('error', $provider->spans[2]->status[0]);
        self::assertSame(1, $collector->snapshot()->requestFailures);
    }

    public function test_provider_shutdown_failures_do_not_leave_lifecycle_diagnostics_started(): void
    {
        $clock = new RuntimeObservabilityClock;
        $collector = new WorkerDiagnosticsCollector($clock);
        $observability = new RuntimeObservability(new FailingBoundaryTelemetryProvider, $collector, $clock, 'roadrunner', 'worker-1');

        $observability->applicationStarted();
        $observability->workerStarted();
        $observability->workerStopped();
        $observability->applicationStopped();

        self::assertSame('application.stopped', $collector->snapshot()->lifecycleState);
        self::assertSame(2, $collector->snapshot()->telemetryFailures);
    }
}

class RecordingTelemetryProvider implements TelemetryProviderInterface
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return $this->spans[] = new RecordingSpan($name, $attributes);
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void {}

    public function observe(string $name, float $value, array $attributes = []): void {}

    public function flush(): void {}

    public function shutdown(): void {}
}

final class RecordingSpan implements SpanInterface
{
    /** @var array<string, scalar|null> */
    public array $attributes;

    /** @var array{string, ?string}|null */
    public ?array $status = null;

    public ?Throwable $exception = null;

    public bool $ended = false;

    public function __construct(public string $name, array $attributes)
    {
        $this->attributes = $attributes;
    }

    public function setAttribute(string $name, string|int|float|bool|null $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function recordException(Throwable $exception, array $attributes = []): void
    {
        $this->exception = $exception;
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

final class RuntimeObservabilityClock implements DiagnosticsClockInterface
{
    private DateTimeImmutable $current;

    public function __construct(private float $monotonic = 10.0)
    {
        $this->current = new DateTimeImmutable('2026-10-03T12:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function monotonicSeconds(): float
    {
        return $this->monotonic;
    }
}

final class FailingBoundaryTelemetryProvider extends RecordingTelemetryProvider
{
    public function flush(): void
    {
        throw new RuntimeException('flush failed');
    }

    public function shutdown(): void
    {
        throw new RuntimeException('shutdown failed');
    }
}
