<?php

declare(strict_types=1);

namespace Tusk\Contracts\Observability;

use DateTimeImmutable;
use JsonSerializable;

final readonly class WorkerDiagnosticsSnapshot implements JsonSerializable
{
    public function __construct(
        public string $workerId,
        public string $runtime,
        public string $lifecycleState,
        public DateTimeImmutable $startedAt,
        public float $uptimeSeconds,
        public int $requestsTotal,
        public int $requestFailures,
        public int $jobsTotal,
        public int $jobFailures,
        public int $inFlight,
        public float $requestDurationTotalSeconds,
        public float $requestDurationMaxSeconds,
        public float $jobDurationTotalSeconds,
        public float $jobDurationMaxSeconds,
        public int $currentMemoryBytes,
        public int $peakMemoryBytes,
        public int $requestScopeResets,
        public int $cleanupAnomalies,
        public int $telemetryFailures,
        public ?DateTimeImmutable $lastFailureAt,
        public ?string $lastFailureCategory,
        public ?string $lastFailureMessage,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'runtime' => $this->runtime,
            'lifecycle_state' => $this->lifecycleState,
            'started_at' => $this->startedAt->format(DateTimeImmutable::ATOM),
            'uptime_seconds' => $this->uptimeSeconds,
            'requests_total' => $this->requestsTotal,
            'request_failures' => $this->requestFailures,
            'jobs_total' => $this->jobsTotal,
            'job_failures' => $this->jobFailures,
            'in_flight' => $this->inFlight,
            'request_duration_total_seconds' => $this->requestDurationTotalSeconds,
            'request_duration_max_seconds' => $this->requestDurationMaxSeconds,
            'job_duration_total_seconds' => $this->jobDurationTotalSeconds,
            'job_duration_max_seconds' => $this->jobDurationMaxSeconds,
            'current_memory_bytes' => $this->currentMemoryBytes,
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'request_scope_resets' => $this->requestScopeResets,
            'cleanup_anomalies' => $this->cleanupAnomalies,
            'telemetry_failures' => $this->telemetryFailures,
            'last_failure_at' => $this->lastFailureAt?->format(DateTimeImmutable::ATOM),
            'last_failure_category' => $this->lastFailureCategory,
            'last_failure_message' => $this->lastFailureMessage,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
