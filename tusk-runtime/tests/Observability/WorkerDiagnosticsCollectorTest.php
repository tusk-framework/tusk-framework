<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Runtime\Observability\DiagnosticsClockInterface;
use Tusk\Runtime\Observability\WorkerDiagnosticsCollector;

final class WorkerDiagnosticsCollectorTest extends TestCase
{
    public function test_collector_records_worker_request_job_and_telemetry_diagnostics(): void
    {
        $clock = new FakeDiagnosticsClock;
        $collector = new WorkerDiagnosticsCollector($clock);

        $collector->applicationStarted();
        $collector->workerStarted('worker-1', 'roadrunner');
        $collector->requestStarted();
        $collector->requestFinished(200, null, 0.25);
        $collector->requestScopeReset();
        $collector->requestStarted();
        $collector->requestFinished(500, new RuntimeException("secret-token\ntrace"), 1.5);
        $collector->requestScopeReset(true);
        $collector->jobStarted('emails');
        $collector->jobFinished(false, new RuntimeException('database password'), 0.75);
        $collector->telemetryFailure(new RuntimeException('collector secret'));

        $snapshot = $collector->snapshot();
        $data = $snapshot->toArray();

        self::assertSame('worker-1', $data['worker_id']);
        self::assertSame('roadrunner', $data['runtime']);
        self::assertSame('worker.started', $data['lifecycle_state']);
        self::assertSame(2, $data['requests_total']);
        self::assertSame(1, $data['request_failures']);
        self::assertSame(1, $data['jobs_total']);
        self::assertSame(1, $data['job_failures']);
        self::assertSame(0, $data['in_flight']);
        self::assertSame(1.75, $data['request_duration_total_seconds']);
        self::assertSame(1.5, $data['request_duration_max_seconds']);
        self::assertSame(0.75, $data['job_duration_total_seconds']);
        self::assertSame(0.75, $data['job_duration_max_seconds']);
        self::assertSame(2, $data['request_scope_resets']);
        self::assertSame(1, $data['cleanup_anomalies']);
        self::assertSame(1, $data['telemetry_failures']);
        self::assertSame('telemetry.failure', $data['last_failure_category']);
        self::assertSame('telemetry failed', $data['last_failure_message']);
        self::assertStringNotContainsString('secret', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertGreaterThanOrEqual(0, $data['current_memory_bytes']);
        self::assertGreaterThanOrEqual($data['current_memory_bytes'], $data['peak_memory_bytes']);
    }

    public function test_collector_transitions_are_idempotent_and_reset_worker_counters(): void
    {
        $clock = new FakeDiagnosticsClock;
        $collector = new WorkerDiagnosticsCollector($clock);

        $collector->applicationStarted();
        $collector->applicationStarted();
        $collector->workerStarted('worker-1', 'native');
        $collector->workerStarted('worker-1', 'native');
        $collector->requestStarted();
        $collector->requestFinished(200, null, 0.1);
        $collector->workerStopped();
        $collector->workerStopped();
        $collector->workerStarted('worker-2', 'native');

        $snapshot = $collector->snapshot()->toArray();

        self::assertSame('worker-2', $snapshot['worker_id']);
        self::assertSame('worker.started', $snapshot['lifecycle_state']);
        self::assertSame(0, $snapshot['requests_total']);
        self::assertSame(0, $snapshot['jobs_total']);
        self::assertSame(0, $snapshot['request_scope_resets']);
    }
}

final class FakeDiagnosticsClock implements DiagnosticsClockInterface
{
    private DateTimeImmutable $now;

    private float $monotonic = 100.0;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-10-03T12:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function monotonicSeconds(): float
    {
        return $this->monotonic;
    }
}
