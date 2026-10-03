<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Contracts\Observability\WorkerDiagnosticsInterface;

final class RuntimeObservability implements LifecycleObserverInterface
{
    private ?SpanInterface $requestSpan = null;

    private ?SpanInterface $jobSpan = null;

    private ?float $requestStartedAt = null;

    private ?float $jobStartedAt = null;

    private string $runtime = 'unknown';

    public function __construct(
        private readonly TelemetryProviderInterface $provider,
        private readonly WorkerDiagnosticsCollector $collector,
        private readonly DiagnosticsClockInterface $clock = new SystemDiagnosticsClock,
        private readonly string $workerId = '',
    ) {}

    public function setRuntime(string $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function diagnostics(): WorkerDiagnosticsInterface
    {
        return $this->collector;
    }

    public function applicationStarted(): void
    {
        $this->collector->applicationStarted();
        $this->shortSpan('tusk.application.start');
    }

    public function workerStarted(): void
    {
        $this->collector->workerStarted($this->workerId !== '' ? $this->workerId : (string) getmypid(), $this->runtime);
        $this->shortSpan('tusk.worker.start');
    }

    public function requestStarted(mixed $request = null): void
    {
        $this->collector->requestStarted();
        $this->requestStartedAt = $this->clock->monotonicSeconds();
        $attributes = [];

        if ($request instanceof ServerRequestInterface) {
            $attributes['http.method'] = $request->getMethod();
            $attributes['url.path'] = $request->getUri()->getPath();
        }

        $this->requestSpan = $this->startSpan('tusk.http.server', $attributes);
    }

    public function requestFinished(mixed $response = null, ?Throwable $exception = null): void
    {
        $duration = $this->durationSince($this->requestStartedAt);
        $status = $response instanceof ResponseInterface ? $response->getStatusCode() : null;
        $this->collector->requestFinished($status, $exception, $duration);

        if ($this->requestSpan !== null) {
            if ($status !== null) {
                $this->requestSpan->setAttribute('http.status_code', $status);
            }

            if ($exception !== null) {
                $this->requestSpan->recordException($exception, ['category' => 'request.failure']);
                $this->requestSpan->setStatus('error', 'request failed');
            } elseif ($status !== null && $status >= 500) {
                $this->requestSpan->setStatus('error', 'server error');
            } else {
                $this->requestSpan->setStatus('ok');
            }

            $this->endSpan($this->requestSpan);
        }

        $this->requestSpan = null;
        $this->requestStartedAt = null;
        $this->metricIncrement('tusk.requests.total', 1, ['status_code' => $status ?? 0]);
        $this->metricObserve('tusk.request.duration', $duration);
    }

    public function jobStarted(string $name, ?string $id = null): void
    {
        $this->collector->jobStarted($name);
        $this->jobStartedAt = $this->clock->monotonicSeconds();
        $attributes = ['job.name' => $name];
        if ($id !== null) {
            $attributes['job.id'] = $id;
        }

        $this->jobSpan = $this->startSpan('tusk.job.process', $attributes);
    }

    public function jobFinished(bool $success, ?Throwable $exception = null): void
    {
        $duration = $this->durationSince($this->jobStartedAt);
        $this->collector->jobFinished($success, $exception, $duration);

        if ($this->jobSpan !== null) {
            if ($exception !== null) {
                $this->jobSpan->recordException($exception, ['category' => 'job.failure']);
            }
            $this->jobSpan->setStatus($success ? 'ok' : 'error', $success ? null : 'job failed');
            $this->endSpan($this->jobSpan);
        }

        $this->jobSpan = null;
        $this->jobStartedAt = null;
        $this->metricIncrement('tusk.jobs.total');
        $this->metricObserve('tusk.job.duration', $duration);
    }

    public function workerStopped(): void
    {
        try {
            $this->shortSpan('tusk.worker.stop');
            $this->provider->flush();
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);
        } finally {
            $this->collector->workerStopped();
        }
    }

    public function applicationStopped(): void
    {
        try {
            $this->shortSpan('tusk.application.stop');
            $this->provider->shutdown();
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);
        } finally {
            $this->collector->applicationStopped();
        }
    }

    private function shortSpan(string $name): void
    {
        $span = $this->startSpan($name);
        if ($span !== null) {
            $this->endSpan($span);
        }
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function startSpan(string $name, array $attributes = []): ?SpanInterface
    {
        try {
            return $this->provider->startSpan($name, $attributes);
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);

            return null;
        }
    }

    private function endSpan(SpanInterface $span): void
    {
        try {
            $span->end();
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);
        }
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function metricIncrement(string $name, int|float $value = 1, array $attributes = []): void
    {
        try {
            $this->provider->increment($name, $value, $attributes);
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);
        }
    }

    private function metricObserve(string $name, float $value): void
    {
        try {
            $this->provider->observe($name, $value);
        } catch (Throwable $exception) {
            $this->collector->telemetryFailure($exception);
        }
    }

    private function durationSince(?float $startedAt): float
    {
        if ($startedAt === null) {
            return 0.0;
        }

        return max(0.0, $this->clock->monotonicSeconds() - $startedAt);
    }
}
