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
use Tusk\Contracts\Observability\WorkerLifecycleCheckpointInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Modules\RuntimeObservabilityModule;
use Tusk\Runtime\Observability\DiagnosticsClockInterface;
use Tusk\Runtime\Observability\ObservabilityConfiguration;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Runtime\Observability\WorkerDiagnosticsCollector;

final class RuntimeObservabilityTest extends TestCase
{
    public function test_failed_job_telemetry_uses_only_bounded_job_name_and_fixed_error_status(): void
    {
        $provider = new RecordingTelemetryProvider;
        $collector = new WorkerDiagnosticsCollector;
        $observability = new RuntimeObservability($provider, $collector);
        $failure = new RuntimeException('failed');

        $observability->jobStarted('mail.welcome', 'delivery-1');
        $observability->jobFinished(false, $failure);

        self::assertSame(['job.name' => 'mail.welcome'], $provider->spans[0]->attributes);
        self::assertNull($provider->spans[0]->exception);
        self::assertSame(['error', 'job failed'], $provider->spans[0]->status);
        self::assertTrue($provider->spans[0]->ended);
        self::assertSame([['tusk.jobs.total', 1, ['job.name' => 'mail.welcome']]], $provider->increments);
        self::assertSame(1, $collector->snapshot()->jobsTotal);
        self::assertSame(1, $collector->snapshot()->jobFailures);
    }

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

    public function test_optional_checkpoint_receives_worker_and_completed_operation_boundaries(): void
    {
        $checkpoint = new RecordingLifecycleCheckpoint;
        $container = new Container;
        $container->instance(WorkerLifecycleCheckpointInterface::class, $checkpoint);
        (new RuntimeObservabilityModule(ObservabilityConfiguration::fromArray([])))->register($container);
        $observability = $container->get(RuntimeObservability::class);

        $observability->workerStarted();
        $observability->requestStarted();
        $observability->requestFinished();
        $observability->jobStarted('mail');
        $observability->jobFinished(true);
        $observability->workerStopped();

        self::assertSame(['worker_started', 'request_finished', 'job_finished', 'worker_stopped'], $checkpoint->boundaries);
    }

    public function test_checkpoint_failure_cannot_change_lifecycle_result(): void
    {
        $checkpoint = new RecordingLifecycleCheckpoint;
        $checkpoint->fail = true;
        $observability = new RuntimeObservability(new RecordingTelemetryProvider, new WorkerDiagnosticsCollector, checkpoint: $checkpoint);

        $observability->workerStarted();
        $observability->requestStarted();
        $observability->requestFinished(new Response(200));
        $observability->jobStarted('mail');
        $observability->jobFinished(true);
        $observability->workerStopped();

        self::assertSame(['worker_started', 'request_finished', 'job_finished', 'worker_stopped'], $checkpoint->boundaries);
    }
}

final class RecordingLifecycleCheckpoint implements WorkerLifecycleCheckpointInterface
{
    /** @var list<string> */
    public array $boundaries = [];

    public bool $fail = false;

    public function checkpoint(string $boundary): void
    {
        $this->boundaries[] = $boundary;
        if ($this->fail) {
            throw new RuntimeException('checkpoint failed');
        }
    }
}

class RecordingTelemetryProvider implements TelemetryProviderInterface
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    public array $increments = [];

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        return $this->spans[] = new RecordingSpan($name, $attributes);
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->increments[] = [$name, $value, $attributes];
    }

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
