<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use DateTimeImmutable;
use Throwable;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsSnapshot;

final class WorkerDiagnosticsCollector implements WorkerDiagnosticsInterface
{
    private string $workerId = 'unknown';

    private string $runtime = 'unknown';

    private string $lifecycleState = 'standalone';

    private DateTimeImmutable $startedAt;

    private float $startedMonotonic;

    private bool $applicationStarted = false;

    private bool $workerStarted = false;

    private int $requestsTotal = 0;

    private int $requestFailures = 0;

    private int $jobsTotal = 0;

    private int $jobFailures = 0;

    private int $inFlight = 0;

    private float $requestDurationTotalSeconds = 0.0;

    private float $requestDurationMaxSeconds = 0.0;

    private float $jobDurationTotalSeconds = 0.0;

    private float $jobDurationMaxSeconds = 0.0;

    private int $requestScopeResets = 0;

    private int $cleanupAnomalies = 0;

    private int $telemetryFailures = 0;

    private ?DateTimeImmutable $lastFailureAt = null;

    private ?string $lastFailureCategory = null;

    private ?string $lastFailureMessage = null;

    public function __construct(?DiagnosticsClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemDiagnosticsClock;
        $this->startedAt = $this->clock->now();
        $this->startedMonotonic = $this->clock->monotonicSeconds();
    }

    private readonly DiagnosticsClockInterface $clock;

    public function applicationStarted(): void
    {
        if ($this->applicationStarted) {
            return;
        }

        $this->applicationStarted = true;
        $this->startedAt = $this->clock->now();
        $this->startedMonotonic = $this->clock->monotonicSeconds();
        $this->lifecycleState = 'application.started';
    }

    public function workerStarted(string $workerId, string $runtime): void
    {
        if ($this->workerStarted) {
            return;
        }

        $this->workerId = $workerId;
        $this->runtime = $runtime;
        $this->workerStarted = true;
        $this->lifecycleState = 'worker.started';
        $this->resetWorkerCounters();
        $this->startedAt = $this->clock->now();
        $this->startedMonotonic = $this->clock->monotonicSeconds();
    }

    public function requestStarted(): void
    {
        $this->inFlight++;
        $this->lifecycleState = 'request.started';
    }

    public function requestFinished(?int $status, ?Throwable $exception, float $durationSeconds): void
    {
        $this->requestsTotal++;
        $this->requestFailures += $exception !== null || ($status !== null && $status >= 500) ? 1 : 0;
        $this->requestDurationTotalSeconds += max(0.0, $durationSeconds);
        $this->requestDurationMaxSeconds = max($this->requestDurationMaxSeconds, $durationSeconds);
        $this->inFlight = max(0, $this->inFlight - 1);
        $this->lifecycleState = $this->workerStarted ? 'worker.started' : $this->lifecycleState;

        if ($exception !== null || ($status !== null && $status >= 500)) {
            $this->recordFailure('request.failure', 'request failed');
        }
    }

    public function jobStarted(string $name): void
    {
        $this->inFlight++;
        $this->lifecycleState = 'job.started';
    }

    public function jobFinished(bool $success, ?Throwable $exception, float $durationSeconds): void
    {
        $this->jobsTotal++;
        $this->jobFailures += $success ? 0 : 1;
        $this->jobDurationTotalSeconds += max(0.0, $durationSeconds);
        $this->jobDurationMaxSeconds = max($this->jobDurationMaxSeconds, $durationSeconds);
        $this->inFlight = max(0, $this->inFlight - 1);
        $this->lifecycleState = $this->workerStarted ? 'worker.started' : $this->lifecycleState;

        if (! $success) {
            $this->recordFailure('job.failure', 'job failed');
        }
    }

    public function requestScopeReset(bool $anomaly = false): void
    {
        $this->requestScopeResets++;
        $this->cleanupAnomalies += $anomaly ? 1 : 0;
    }

    public function telemetryFailure(Throwable $exception): void
    {
        $this->telemetryFailures++;
        $this->recordFailure('telemetry.failure', 'telemetry failed');
    }

    public function workerStopped(): void
    {
        if (! $this->workerStarted) {
            return;
        }

        $this->workerStarted = false;
        $this->inFlight = 0;
        $this->lifecycleState = 'worker.stopped';
    }

    public function applicationStopped(): void
    {
        if (! $this->applicationStarted) {
            return;
        }

        $this->applicationStarted = false;
        $this->lifecycleState = 'application.stopped';
    }

    public function snapshot(): WorkerDiagnosticsSnapshot
    {
        return new WorkerDiagnosticsSnapshot(
            workerId: $this->workerId,
            runtime: $this->runtime,
            lifecycleState: $this->lifecycleState,
            startedAt: $this->startedAt,
            uptimeSeconds: max(0.0, $this->clock->monotonicSeconds() - $this->startedMonotonic),
            requestsTotal: $this->requestsTotal,
            requestFailures: $this->requestFailures,
            jobsTotal: $this->jobsTotal,
            jobFailures: $this->jobFailures,
            inFlight: $this->inFlight,
            requestDurationTotalSeconds: $this->requestDurationTotalSeconds,
            requestDurationMaxSeconds: $this->requestDurationMaxSeconds,
            jobDurationTotalSeconds: $this->jobDurationTotalSeconds,
            jobDurationMaxSeconds: $this->jobDurationMaxSeconds,
            currentMemoryBytes: memory_get_usage(true),
            peakMemoryBytes: memory_get_peak_usage(true),
            requestScopeResets: $this->requestScopeResets,
            cleanupAnomalies: $this->cleanupAnomalies,
            telemetryFailures: $this->telemetryFailures,
            lastFailureAt: $this->lastFailureAt,
            lastFailureCategory: $this->lastFailureCategory,
            lastFailureMessage: $this->lastFailureMessage,
        );
    }

    private function resetWorkerCounters(): void
    {
        $this->requestsTotal = 0;
        $this->requestFailures = 0;
        $this->jobsTotal = 0;
        $this->jobFailures = 0;
        $this->inFlight = 0;
        $this->requestDurationTotalSeconds = 0.0;
        $this->requestDurationMaxSeconds = 0.0;
        $this->jobDurationTotalSeconds = 0.0;
        $this->jobDurationMaxSeconds = 0.0;
        $this->requestScopeResets = 0;
        $this->cleanupAnomalies = 0;
        $this->telemetryFailures = 0;
        $this->lastFailureAt = null;
        $this->lastFailureCategory = null;
        $this->lastFailureMessage = null;
    }

    private function recordFailure(string $category, string $message): void
    {
        $this->lastFailureAt = $this->clock->now();
        $this->lastFailureCategory = $category;
        $this->lastFailureMessage = substr(str_replace(["\r", "\n"], ' ', $message), 0, 160);
    }
}
