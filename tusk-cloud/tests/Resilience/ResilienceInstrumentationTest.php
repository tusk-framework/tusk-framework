<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Event\CircuitStateChanged;
use Tusk\Cloud\Resilience\Event\FallbackApplied;
use Tusk\Cloud\Resilience\Event\OperationRejected;
use Tusk\Cloud\Resilience\Event\OperationRejectionReason;
use Tusk\Cloud\Resilience\Event\RetryScheduled;
use Tusk\Cloud\Resilience\ResilienceInstrumentation;
use Tusk\Cloud\Resilience\State;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

final class ResilienceInstrumentationTest extends TestCase
{
    public function test_retry_dispatches_event_and_increments_counter_without_attributes(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $telemetry = new RecordingResilienceTelemetry;
        $event = new RetryScheduled('secret.operation', 2, 150, 'PrivateFailure');

        (new ResilienceInstrumentation($dispatcher, $telemetry))->retryScheduled($event);

        self::assertSame([$event], $dispatcher->events);
        self::assertSame([['tusk.resilience.retries', 1, []]], $telemetry->increments);
        self::assertSame([], $telemetry->observations);
    }

    public function test_fallback_dispatches_without_a_metric(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $telemetry = new RecordingResilienceTelemetry;
        $event = new FallbackApplied('secret.operation', 'PrivateFailure');

        (new ResilienceInstrumentation($dispatcher, $telemetry))->fallbackApplied($event);

        self::assertSame([$event], $dispatcher->events);
        self::assertSame([], $telemetry->increments);
        self::assertSame([], $telemetry->observations);
    }

    public function test_rejection_counter_uses_only_fixed_reason_values(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $telemetry = new RecordingResilienceTelemetry;
        $instrumentation = new ResilienceInstrumentation($dispatcher, $telemetry);
        $events = [];
        $expected = [];

        foreach (OperationRejectionReason::cases() as $reason) {
            $event = new OperationRejected('secret.operation', $reason);
            $events[] = $event;
            $expected[] = ['tusk.resilience.rejections', 1, ['reason' => $reason->value]];
            $instrumentation->operationRejected($event);
        }

        self::assertSame($events, $dispatcher->events);
        self::assertSame($expected, $telemetry->increments);
    }

    public function test_circuit_counter_uses_only_bounded_state_labels(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $telemetry = new RecordingResilienceTelemetry;
        $instrumentation = new ResilienceInstrumentation($dispatcher, $telemetry);
        $events = [
            new CircuitStateChanged('secret.operation', State::CLOSED, State::OPEN),
            new CircuitStateChanged('secret.operation', State::OPEN, State::HALF_OPEN),
            new CircuitStateChanged('secret.operation', State::HALF_OPEN, State::CLOSED),
        ];

        foreach ($events as $event) {
            $instrumentation->circuitStateChanged($event);
        }

        self::assertSame($events, $dispatcher->events);
        self::assertSame([
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'CLOSED', 'to' => 'OPEN']],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'OPEN', 'to' => 'HALF_OPEN']],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'HALF_OPEN', 'to' => 'CLOSED']],
        ], $telemetry->increments);
    }

    public function test_each_allowed_outcome_records_once_and_observes_elapsed_seconds(): void
    {
        $telemetry = new RecordingResilienceTelemetry;
        $clock = new CountingResilienceClock(1_000);
        $instrumentation = new ResilienceInstrumentation(null, $telemetry, $clock);

        foreach (['success', 'failure', 'fallback_success', 'fallback_failure'] as $outcome) {
            $startedAt = $instrumentation->beginOperation();
            self::assertSame(1_000, $startedAt);
            $clock->sleepMilliseconds(2_750);
            $instrumentation->finishOperation($startedAt, $outcome);
            $clock->now = 1_000;
        }

        self::assertSame([
            ['tusk.resilience.operations', 1, ['outcome' => 'success']],
            ['tusk.resilience.operations', 1, ['outcome' => 'failure']],
            ['tusk.resilience.operations', 1, ['outcome' => 'fallback_success']],
            ['tusk.resilience.operations', 1, ['outcome' => 'fallback_failure']],
        ], $telemetry->increments);
        self::assertSame([
            ['tusk.resilience.operation.duration', 2.75, ['outcome' => 'success']],
            ['tusk.resilience.operation.duration', 2.75, ['outcome' => 'failure']],
            ['tusk.resilience.operation.duration', 2.75, ['outcome' => 'fallback_success']],
            ['tusk.resilience.operation.duration', 2.75, ['outcome' => 'fallback_failure']],
        ], $telemetry->observations);
        self::assertSame(8, $clock->reads);
    }

    public function test_duration_is_clamped_at_zero_if_clock_moves_backwards(): void
    {
        $telemetry = new RecordingResilienceTelemetry;
        $clock = new CountingResilienceClock(100);
        $instrumentation = new ResilienceInstrumentation(null, $telemetry, $clock);
        $startedAt = $instrumentation->beginOperation();
        $clock->now = 50;

        $instrumentation->finishOperation($startedAt, 'failure');

        self::assertSame([['tusk.resilience.operation.duration', 0.0, ['outcome' => 'failure']]], $telemetry->observations);
    }

    public function test_invalid_outcome_is_rejected_before_recording_any_metric(): void
    {
        $telemetry = new RecordingResilienceTelemetry;
        $instrumentation = new ResilienceInstrumentation(null, $telemetry);

        try {
            $instrumentation->finishOperation(null, 'secret.operation');
            self::fail('Arbitrary outcome was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $telemetry->increments);
            self::assertSame([], $telemetry->observations);
        }
    }

    public function test_no_sinks_are_noop_and_do_not_read_clock(): void
    {
        $clock = new CountingResilienceClock(100);
        $instrumentation = new ResilienceInstrumentation(clock: $clock);

        $instrumentation->retryScheduled(new RetryScheduled('private', 1, 0, 'PrivateFailure'));
        $instrumentation->fallbackApplied(new FallbackApplied('private', 'PrivateFailure'));
        $instrumentation->operationRejected(new OperationRejected('private', OperationRejectionReason::CANCELLED));
        $instrumentation->circuitStateChanged(new CircuitStateChanged('private', State::CLOSED, State::OPEN));
        self::assertNull($instrumentation->beginOperation());
        $instrumentation->finishOperation(null, 'success');

        self::assertSame(0, $clock->reads);
    }

    public function test_dispatcher_failure_does_not_suppress_each_metric(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $dispatcher->throws = true;
        $telemetry = new RecordingResilienceTelemetry;
        $instrumentation = new ResilienceInstrumentation($dispatcher, $telemetry);

        $instrumentation->retryScheduled(new RetryScheduled('private', 1, 0, 'PrivateFailure'));
        $instrumentation->fallbackApplied(new FallbackApplied('private', 'PrivateFailure'));
        $instrumentation->operationRejected(new OperationRejected('private', OperationRejectionReason::CANCELLED));
        $instrumentation->circuitStateChanged(new CircuitStateChanged('private', State::CLOSED, State::OPEN));

        self::assertCount(4, $dispatcher->events);
        self::assertSame([
            ['tusk.resilience.retries', 1, []],
            ['tusk.resilience.rejections', 1, ['reason' => 'cancelled']],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'CLOSED', 'to' => 'OPEN']],
        ], $telemetry->increments);
    }

    public function test_telemetry_failure_does_not_suppress_event_dispatch_or_other_metric_operations(): void
    {
        $dispatcher = new RecordingResilienceDispatcher;
        $telemetry = new RecordingResilienceTelemetry;
        $telemetry->throwOnIncrement = true;
        $telemetry->throwOnObserve = true;
        $clock = new CountingResilienceClock(100);
        $instrumentation = new ResilienceInstrumentation($dispatcher, $telemetry, $clock);
        $event = new RetryScheduled('private', 1, 0, 'PrivateFailure');

        $instrumentation->retryScheduled($event);
        $startedAt = $instrumentation->beginOperation();
        $clock->sleepMilliseconds(250);
        $instrumentation->finishOperation($startedAt, 'success');

        self::assertSame([$event], $dispatcher->events);
        self::assertSame([
            ['tusk.resilience.retries', 1, []],
            ['tusk.resilience.operations', 1, ['outcome' => 'success']],
        ], $telemetry->increments);
        self::assertSame([['tusk.resilience.operation.duration', 0.25, ['outcome' => 'success']]], $telemetry->observations);
    }

    public function test_clock_failure_disables_only_duration_observation(): void
    {
        $telemetry = new RecordingResilienceTelemetry;
        $clock = new CountingResilienceClock(100);
        $clock->throws = true;
        $instrumentation = new ResilienceInstrumentation(null, $telemetry, $clock);

        self::assertNull($instrumentation->beginOperation());
        $instrumentation->finishOperation(null, 'failure');

        self::assertSame(1, $clock->reads);
        self::assertSame([['tusk.resilience.operations', 1, ['outcome' => 'failure']]], $telemetry->increments);
        self::assertSame([], $telemetry->observations);
    }

    public function test_clock_failure_at_finish_still_records_outcome_counter(): void
    {
        $telemetry = new RecordingResilienceTelemetry;
        $clock = new CountingResilienceClock(100);
        $instrumentation = new ResilienceInstrumentation(null, $telemetry, $clock);
        $startedAt = $instrumentation->beginOperation();
        $clock->throws = true;

        $instrumentation->finishOperation($startedAt, 'fallback_failure');

        self::assertSame([['tusk.resilience.operations', 1, ['outcome' => 'fallback_failure']]], $telemetry->increments);
        self::assertSame([], $telemetry->observations);
    }
}

final class RecordingResilienceDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public bool $throws = false;

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        if ($this->throws) {
            throw new RuntimeException('Dispatcher failure');
        }

        return $event;
    }
}

final class RecordingResilienceTelemetry implements TelemetryProviderInterface
{
    /** @var list<array{string, int|float, array<string, scalar|null>}> */
    public array $increments = [];

    /** @var list<array{string, float, array<string, scalar|null>}> */
    public array $observations = [];

    public bool $throwOnIncrement = false;

    public bool $throwOnObserve = false;

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        throw new LogicException('Unexpected span creation');
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->increments[] = [$name, $value, $attributes];

        if ($this->throwOnIncrement) {
            throw new RuntimeException('Counter failure');
        }
    }

    public function observe(string $name, float $value, array $attributes = []): void
    {
        $this->observations[] = [$name, $value, $attributes];

        if ($this->throwOnObserve) {
            throw new RuntimeException('Observation failure');
        }
    }

    public function flush(): void
    {
        throw new LogicException('Unexpected telemetry flush');
    }

    public function shutdown(): void
    {
        throw new LogicException('Unexpected telemetry shutdown');
    }
}

final class CountingResilienceClock implements ClockInterface
{
    public int $reads = 0;

    public bool $throws = false;

    public function __construct(public int $now) {}

    public function nowMilliseconds(): int
    {
        $this->reads++;

        if ($this->throws) {
            throw new RuntimeException('Clock failure');
        }

        return $this->now;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->now += $milliseconds;
    }
}
