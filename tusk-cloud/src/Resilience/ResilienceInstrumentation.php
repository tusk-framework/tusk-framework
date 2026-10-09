<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Throwable;
use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Cloud\Resilience\Event\CircuitStateChanged;
use Tusk\Cloud\Resilience\Event\FallbackApplied;
use Tusk\Cloud\Resilience\Event\OperationRejected;
use Tusk\Cloud\Resilience\Event\RetryScheduled;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

final class ResilienceInstrumentation
{
    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?TelemetryProviderInterface $telemetry = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?ResilienceDiagnosticsRegistry $registry = null,
        private readonly ?EngineResilienceReporter $reporter = null,
    ) {}

    public function retryScheduled(RetryScheduled $event): void
    {
        $this->dispatch($event);
        $this->increment('tusk.resilience.retries');
    }

    public function fallbackApplied(FallbackApplied $event): void
    {
        $this->dispatch($event);
    }

    public function operationRejected(OperationRejected $event): void
    {
        $this->dispatch($event);
        $this->increment('tusk.resilience.rejections', ['reason' => $event->reason->value]);
    }

    public function circuitStateChanged(CircuitStateChanged $event, ?string $circuitName = null): void
    {
        $this->dispatch($event);
        $circuitName ??= $event->operation;
        if ($this->registry?->contains($circuitName)) {
            try {
                $snapshot = $this->registry->snapshot();
                foreach ($snapshot->circuits() as $circuit) {
                    if ($circuit['name'] === $circuitName && $circuit['state'] === strtolower($event->current->value)) {
                        $this->reporter?->reportTransition();

                        break;
                    }
                }
            } catch (Throwable) {
                // A failed diagnostic read or report cannot affect the policy.
            }
        }
        $this->increment('tusk.resilience.circuit.transitions', [
            'from' => $event->previous->value,
            'to' => $event->current->value,
        ]);
    }

    public function beginOperation(): ?int
    {
        if ($this->telemetry === null || $this->clock === null) {
            return null;
        }

        try {
            return $this->clock->nowMilliseconds();
        } catch (Throwable) {
            return null;
        }
    }

    public function finishOperation(?int $startedAt, string $outcome): void
    {
        if (! in_array($outcome, ['success', 'failure', 'fallback_success', 'fallback_failure'], true)) {
            throw new InvalidArgumentException('Invalid resilience operation outcome.');
        }

        $attributes = ['outcome' => $outcome];
        $this->increment('tusk.resilience.operations', $attributes);

        if ($this->telemetry === null || $startedAt === null || $this->clock === null) {
            return;
        }

        try {
            $elapsedMilliseconds = max(0, $this->clock->nowMilliseconds() - $startedAt);
        } catch (Throwable) {
            return;
        }

        $this->observe('tusk.resilience.operation.duration', $elapsedMilliseconds / 1000, $attributes);
    }

    private function dispatch(object $event): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch($event);
        } catch (Throwable) {
            // Instrumentation must not change the operation's result.
        }
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function increment(string $name, array $attributes = []): void
    {
        if ($this->telemetry === null) {
            return;
        }

        try {
            $this->telemetry->increment($name, 1, $attributes);
        } catch (Throwable) {
            // A failed counter must not suppress another sink operation.
        }
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function observe(string $name, float $value, array $attributes): void
    {
        if ($this->telemetry === null) {
            return;
        }

        try {
            $this->telemetry->observe($name, $value, $attributes);
        } catch (Throwable) {
            // Observation is independent of the outcome counter.
        }
    }
}
