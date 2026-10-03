<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Observability;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsSnapshot;
use Throwable;

final class ObservabilityContractTest extends TestCase
{
    public function test_span_contract_exposes_provider_neutral_operations(): void
    {
        self::assertSame(
            ['setAttribute', 'recordException', 'setStatus', 'end'],
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(SpanInterface::class))->getMethods()),
        );
    }

    public function test_telemetry_provider_contract_exposes_spans_metrics_and_shutdown(): void
    {
        self::assertSame(
            ['startSpan', 'increment', 'observe', 'flush', 'shutdown'],
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(TelemetryProviderInterface::class))->getMethods()),
        );
    }

    public function test_worker_diagnostics_contract_returns_an_immutable_snapshot(): void
    {
        self::assertTrue((new ReflectionClass(WorkerDiagnosticsSnapshot::class))->isReadOnly());
        self::assertSame(['snapshot'], array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(WorkerDiagnosticsInterface::class))->getMethods(),
        ));
    }

    public function test_snapshot_serializes_only_stable_scalar_operational_fields(): void
    {
        $snapshot = new WorkerDiagnosticsSnapshot(
            workerId: 'worker-1',
            runtime: 'roadrunner',
            lifecycleState: 'worker.started',
            startedAt: new DateTimeImmutable('2026-10-03T12:00:00+00:00'),
            uptimeSeconds: 12.5,
            requestsTotal: 4,
            requestFailures: 1,
            jobsTotal: 2,
            jobFailures: 0,
            inFlight: 1,
            requestDurationTotalSeconds: 1.25,
            requestDurationMaxSeconds: 0.8,
            jobDurationTotalSeconds: 0.4,
            jobDurationMaxSeconds: 0.3,
            currentMemoryBytes: 1024,
            peakMemoryBytes: 2048,
            requestScopeResets: 4,
            cleanupAnomalies: 0,
            telemetryFailures: 0,
            lastFailureAt: new DateTimeImmutable('2026-10-03T12:00:05+00:00'),
            lastFailureCategory: 'request.error',
            lastFailureMessage: 'redacted failure',
        );

        $data = $snapshot->toArray();

        self::assertSame('worker-1', $data['worker_id']);
        self::assertSame('2026-10-03T12:00:00+00:00', $data['started_at']);
        self::assertSame('2026-10-03T12:00:05+00:00', $data['last_failure_at']);
        self::assertSame($data, $snapshot->jsonSerialize());
        self::assertSame($data, json_decode((string) json_encode($snapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([], array_filter($data, static fn (mixed $value): bool => ! is_scalar($value) && $value !== null));
    }

    public function test_snapshot_rejects_non_scalar_worker_identity(): void
    {
        $this->expectException(\TypeError::class);

        new WorkerDiagnosticsSnapshot(
            workerId: ['unsafe'],
            runtime: 'roadrunner',
            lifecycleState: 'worker.started',
            startedAt: new DateTimeImmutable,
            uptimeSeconds: 0.0,
            requestsTotal: 0,
            requestFailures: 0,
            jobsTotal: 0,
            jobFailures: 0,
            inFlight: 0,
            requestDurationTotalSeconds: 0.0,
            requestDurationMaxSeconds: 0.0,
            jobDurationTotalSeconds: 0.0,
            jobDurationMaxSeconds: 0.0,
            currentMemoryBytes: 0,
            peakMemoryBytes: 0,
            requestScopeResets: 0,
            cleanupAnomalies: 0,
            telemetryFailures: 0,
            lastFailureAt: null,
            lastFailureCategory: null,
            lastFailureMessage: null,
        );
    }
}
