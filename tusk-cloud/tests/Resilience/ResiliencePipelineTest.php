<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Event\CircuitStateChanged;
use Tusk\Cloud\Resilience\Event\FallbackApplied;
use Tusk\Cloud\Resilience\Event\OperationRejected;
use Tusk\Cloud\Resilience\Event\OperationRejectionReason;
use Tusk\Cloud\Resilience\Event\RetryScheduled;
use Tusk\Cloud\Resilience\Exception\BulkheadRejectedException;
use Tusk\Cloud\Resilience\Exception\BulkheadTimeoutException;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Cloud\Resilience\Exception\ResilienceFallbackException;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResilienceInstrumentation;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\State;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;

final class ResiliencePipelineTest extends TestCase
{
    #[DataProvider('finalPaths')]
    public function test_one_logical_outcome_preserves_results_and_failures_when_sinks_throw(string $outcome, bool $dispatcherThrows, bool $telemetryThrows): void
    {
        $clock = new FakeClock(100);
        $events = $increments = $observations = [];
        $factory = $this->observedFactory($clock, $events, $increments, $observations, $dispatcherThrows, $telemetryThrows);
        $original = new RuntimeException('private operation failure');
        $fallbackFailure = new LogicException('private fallback failure');
        $result = new \stdClass;
        $context = OperationContext::create('custom.operation', retryAllowed: true, metadata: ['user' => 'private']);
        $builder = $factory->pipeline('configured.operation')->withRetry(RetryPolicy::create(
            maxAttempts: 2,
            backoffStrategy: FixedBackoff::create(30),
            classifier: $this->retryPolicy(2)->classifier(),
        ));
        $fallbackCalls = 0;
        if (str_starts_with($outcome, 'fallback_')) {
            $builder = $builder->withFallback(static function (Throwable $failure, OperationContext $received) use ($clock, &$events, &$fallbackCalls, $original, $fallbackFailure, $result, $context, $outcome): object {
                $fallbackCalls++;
                self::assertSame($original, $failure);
                self::assertSame($context, $received);
                self::assertEquals(new FallbackApplied('custom.operation', RuntimeException::class), $events[1] ?? null);
                $clock->sleepMilliseconds(20);
                if ($outcome === 'fallback_failure') {
                    throw $fallbackFailure;
                }

                return $result;
            });
        }

        $attempts = 0;
        $caught = null;
        $actual = null;
        try {
            $actual = $builder->run(static function (OperationContext $received) use ($clock, &$attempts, $original, $result, $context, $outcome): object {
                self::assertSame($context, $received);
                $clock->sleepMilliseconds(10);
                if (++$attempts === 1 || $outcome !== 'success') {
                    throw $original;
                }

                return $result;
            }, $context);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        if ($outcome === 'failure') {
            self::assertSame($original, $caught);
        } elseif ($outcome === 'fallback_failure') {
            self::assertInstanceOf(ResilienceFallbackException::class, $caught);
            self::assertSame($original, $caught->getPrevious());
            self::assertSame($fallbackFailure, $caught->fallbackFailure());
        } else {
            self::assertNull($caught);
            self::assertSame($result, $actual);
        }
        self::assertSame(2, $attempts);
        $hasFallback = str_starts_with($outcome, 'fallback_');
        self::assertSame($hasFallback ? 1 : 0, $fallbackCalls);
        $expectedEvents = [new RetryScheduled('custom.operation', 1, 30, RuntimeException::class)];
        if ($hasFallback) {
            $expectedEvents[] = new FallbackApplied('custom.operation', RuntimeException::class);
        }
        self::assertEquals($expectedEvents, $events);
        self::assertSame([
            ['tusk.resilience.retries', 1, []],
            ['tusk.resilience.operations', 1, ['outcome' => $outcome]],
        ], $increments);
        self::assertSame([
            ['tusk.resilience.operation.duration', $hasFallback ? 0.07 : 0.05, ['outcome' => $outcome]],
        ], $observations);
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function finalPaths(): array
    {
        $cases = [];
        foreach (['success', 'failure', 'fallback_success', 'fallback_failure'] as $outcome) {
            foreach (self::sinkFailures() as $label => [$dispatcherThrows, $telemetryThrows]) {
                $cases[$outcome.' / '.$label] = [$outcome, $dispatcherThrows, $telemetryThrows];
            }
        }

        return $cases;
    }

    #[DataProvider('rejectionPaths')]
    public function test_only_known_rejections_are_observed_and_original_failures_survive_fallback_and_sink_errors(Throwable $original, OperationRejectionReason $reason, string $outcome, bool $dispatcherThrows, bool $telemetryThrows): void
    {
        $clock = new FakeClock;
        $events = $increments = $observations = [];
        $factory = $this->observedFactory($clock, $events, $increments, $observations, $dispatcherThrows, $telemetryThrows);
        $context = OperationContext::create('custom.operation');
        $builder = $factory->pipeline('configured.operation');
        $result = new \stdClass;
        $fallbackFailure = new LogicException('fallback failed');
        if ($outcome !== 'failure') {
            $builder = $builder->withFallback(static function (Throwable $failure, OperationContext $received) use (&$events, $original, $reason, $context, $outcome, $result, $fallbackFailure): object {
                self::assertSame($original, $failure);
                self::assertSame($context, $received);
                self::assertEquals([
                    new OperationRejected('custom.operation', $reason),
                    new FallbackApplied('custom.operation', $original::class),
                ], $events);
                if ($outcome === 'fallback_failure') {
                    throw $fallbackFailure;
                }

                return $result;
            });
        }

        $caught = null;
        $actual = null;
        try {
            $actual = $builder->run(static function () use ($original): never {
                throw $original;
            }, $context);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        if ($outcome === 'failure') {
            self::assertSame($original, $caught);
        } elseif ($outcome === 'fallback_failure') {
            self::assertInstanceOf(ResilienceFallbackException::class, $caught);
            self::assertSame($original, $caught->getPrevious());
            self::assertSame($fallbackFailure, $caught->fallbackFailure());
        } else {
            self::assertNull($caught);
            self::assertSame($result, $actual);
        }
        $expectedEvents = [new OperationRejected('custom.operation', $reason)];
        if ($outcome !== 'failure') {
            $expectedEvents[] = new FallbackApplied('custom.operation', $original::class);
        }
        self::assertEquals($expectedEvents, $events);
        self::assertSame([
            ['tusk.resilience.rejections', 1, ['reason' => $reason->value]],
            ['tusk.resilience.operations', 1, ['outcome' => $outcome]],
        ], $increments);
        self::assertSame([
            ['tusk.resilience.operation.duration', 0.0, ['outcome' => $outcome]],
        ], $observations);
    }

    /** @return array<string, array{Throwable, OperationRejectionReason, string, bool, bool}> */
    public static function rejectionPaths(): array
    {
        $rejections = [
            [new CircuitOpenException, OperationRejectionReason::CIRCUIT_OPEN],
            [new BulkheadRejectedException, OperationRejectionReason::BULKHEAD_REJECTED],
            [new BulkheadTimeoutException, OperationRejectionReason::BULKHEAD_TIMEOUT],
            [new RateLimitRejectedException, OperationRejectionReason::RATE_LIMIT_REJECTED],
            [new ResilienceDeadlineExceededException('custom.operation'), OperationRejectionReason::DEADLINE_EXCEEDED],
            [new OperationCancelledException('custom.operation'), OperationRejectionReason::CANCELLED],
        ];
        $cases = [];
        foreach ($rejections as [$failure, $reason]) {
            foreach (['failure', 'fallback_success', 'fallback_failure'] as $outcome) {
                foreach (self::sinkFailures() as $label => [$dispatcherThrows, $telemetryThrows]) {
                    $cases[$reason->value.' / '.$outcome.' / '.$label] = [$failure, $reason, $outcome, $dispatcherThrows, $telemetryThrows];
                }
            }
        }

        return $cases;
    }

    public function test_an_application_failure_wrapping_a_rejection_is_not_classified_as_rejection(): void
    {
        $events = $increments = $observations = [];
        $factory = $this->observedFactory(new FakeClock, $events, $increments, $observations);
        $original = new RuntimeException('application failure', previous: new CircuitOpenException);
        $fallbackFailure = new RateLimitRejectedException;
        try {
            $factory->pipeline('application')->withFallback(static function () use ($fallbackFailure): never {
                throw $fallbackFailure;
            })->run(static function () use ($original): never {
                throw $original;
            });
            self::fail('Fallback failure was swallowed.');
        } catch (ResilienceFallbackException $caught) {
            self::assertSame($original, $caught->getPrevious());
            self::assertSame($fallbackFailure, $caught->fallbackFailure());
        }
        self::assertEquals([new FallbackApplied('application', RuntimeException::class)], $events);
        self::assertSame([['tusk.resilience.operations', 1, ['outcome' => 'fallback_failure']]], $increments);
        self::assertSame([['tusk.resilience.operation.duration', 0.0, ['outcome' => 'fallback_failure']]], $observations);
    }

    private function observedFactory(FakeClock $clock, array &$events, array &$increments, array &$observations, bool $dispatcherThrows = false, bool $telemetryThrows = false): ResiliencePipelineFactory
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events, $dispatcherThrows): object {
            $events[] = $event;
            if ($dispatcherThrows) {
                throw new \Error('listener failed');
            }

            return $event;
        });
        $telemetry = $this->createMock(TelemetryProviderInterface::class);
        $telemetry->method('increment')->willReturnCallback(static function (string $name, int|float $value, array $attributes) use (&$increments, $telemetryThrows): void {
            $increments[] = [$name, $value, $attributes];
            if ($telemetryThrows) {
                throw new \Error('counter failed');
            }
        });
        $telemetry->method('observe')->willReturnCallback(static function (string $name, float $value, array $attributes) use (&$observations, $telemetryThrows): void {
            $observations[] = [$name, $value, $attributes];
            if ($telemetryThrows) {
                throw new \Error('observation failed');
            }
        });

        return new ResiliencePipelineFactory($clock, new InMemoryStateStore, $dispatcher, $telemetry);
    }

    public function test_factory_shares_one_adapter_with_builder_copies_pipelines_and_components(): void
    {
        $factory = new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore);
        $builder = $factory->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withCircuitBreaker(CircuitBreakerPolicy::create())
            ->withBulkhead(BulkheadPolicy::create())
            ->withRateLimit(RateLimitPolicy::create())
            ->withFallback(static fn (): string => 'fallback');
        $adapter = (new ReflectionProperty($factory, 'instrumentation'))->getValue($factory);
        self::assertInstanceOf(ResilienceInstrumentation::class, $adapter);
        self::assertSame($adapter, (new ReflectionProperty($builder, 'instrumentation'))->getValue($builder));

        foreach ([$builder->build(), $factory->pipeline('payments.charge')->build(), $factory->pipeline('shipping')->build()] as $pipeline) {
            self::assertSame($adapter, (new ReflectionProperty($pipeline, 'instrumentation'))->getValue($pipeline));
            foreach (['retryExecutor', 'circuitBreaker'] as $property) {
                $component = (new ReflectionProperty($pipeline, $property))->getValue($pipeline);
                self::assertSame($adapter, (new ReflectionProperty($component, 'instrumentation'))->getValue($component));
            }
        }
    }

    #[DataProvider('sinkFailures')]
    public function test_factory_wires_retry_and_circuit_observers_through_builder_copies(bool $dispatcherThrows, bool $telemetryThrows): void
    {
        $events = $increments = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events, $dispatcherThrows): object {
            $events[] = $event;
            if ($dispatcherThrows) {
                throw new LogicException('listener failed');
            }

            return $event;
        });
        $telemetry = $this->createMock(TelemetryProviderInterface::class);
        $telemetry->method('increment')->willReturnCallback(static function (string $name, int|float $value, array $attributes) use (&$increments, $telemetryThrows): void {
            if (in_array($name, ['tusk.resilience.retries', 'tusk.resilience.circuit.transitions'], true)) {
                $increments[] = [$name, $value, $attributes];
            }
            if ($telemetryThrows) {
                throw new LogicException('counter failed');
            }
        });
        $clock = new FakeClock;
        $store = new InMemoryStateStore;
        $factory = new ResiliencePipelineFactory($clock, $store, $dispatcher, $telemetry);
        $pipeline = $factory->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10))
            ->withBulkhead(BulkheadPolicy::create())
            ->withRateLimit(RateLimitPolicy::create(capacity: 10))
            ->withFallback(static fn (): string => 'fallback')
            ->build();
        $context = OperationContext::create('charge', retryAllowed: true, metadata: ['private' => 'secret']);
        $failure = new RuntimeException('private message');
        $attempts = 0;

        self::assertSame('fallback', $pipeline->run(static function () use ($failure, &$attempts): never {
            $attempts++;
            throw $failure;
        }, $context));
        self::assertSame(2, $attempts);
        self::assertSame('OPEN', $store->get('cb:payments.charge')['state']);
        $clock->sleepMilliseconds(10);
        self::assertSame('recovered', $pipeline->run(static fn (): string => 'recovered', $context));
        self::assertSame('CLOSED', $store->get('cb:payments.charge')['state']);
        self::assertEquals([
            new RetryScheduled('charge', 1, 0, RuntimeException::class),
            new CircuitStateChanged('charge', State::CLOSED, State::OPEN),
            new CircuitStateChanged('charge', State::OPEN, State::HALF_OPEN),
            new CircuitStateChanged('charge', State::HALF_OPEN, State::CLOSED),
        ], array_values(array_filter($events, static fn (object $event): bool => $event instanceof RetryScheduled || $event instanceof CircuitStateChanged)));
        self::assertSame([
            ['tusk.resilience.retries', 1, []],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'CLOSED', 'to' => 'OPEN']],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'OPEN', 'to' => 'HALF_OPEN']],
            ['tusk.resilience.circuit.transitions', 1, ['from' => 'HALF_OPEN', 'to' => 'CLOSED']],
        ], $increments);

        $originalFailure = null;
        try {
            $factory->pipeline('shipping')->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1))->run(static function () use ($failure): never {
                throw $failure;
            }, $context);
        } catch (RuntimeException $caught) {
            $originalFailure = $caught;
        }
        self::assertSame($failure, $originalFailure);
    }

    /** @return array<string, array{bool, bool}> */
    public static function sinkFailures(): array
    {
        return [
            'both healthy' => [false, false],
            'dispatcher throws' => [true, false],
            'telemetry throws' => [false, true],
            'both throw' => [true, true],
        ];
    }

    public function test_pipeline_builder_is_immutable_and_propagates_the_same_context(): void
    {
        $factory = new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore);
        $builder = $factory->pipeline('payments.charge');
        $configured = $builder->withBulkhead(BulkheadPolicy::create());
        $context = OperationContext::create('payments.charge');

        self::assertNotSame($builder, $configured);
        self::assertSame('receipt', $configured->run(static function (OperationContext $received) use ($context): string {
            self::assertSame($context, $received);

            return 'receipt';
        }, $context));
    }

    public function test_circuit_breaker_records_only_the_final_retry_outcome(): void
    {
        $store = new InMemoryStateStore;
        $factory = new ResiliencePipelineFactory(new FakeClock, $store);
        $pipeline = $factory->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1));
        $context = OperationContext::create('payments.charge', retryAllowed: true);
        $attempts = 0;

        $result = $pipeline->run(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('transient');
            }

            return 'paid';
        }, $context);

        self::assertSame('paid', $result);
        self::assertSame(2, $attempts);
        self::assertSame('CLOSED', $store->get('cb:payments.charge')['state']);
        self::assertSame(0, $store->get('cb:payments.charge')['failureCount']);
    }

    public function test_circuit_breaker_respects_failure_classification(): void
    {
        $store = new InMemoryStateStore;
        $classifier = new class implements FailureClassifierInterface
        {
            public function classify(Throwable $failure, OperationContext $context): FailureDecision
            {
                return FailureDecision::terminal(circuitFailure: false);
            }
        };
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, $store))
            ->pipeline('payments.charge')
            ->withRetry(RetryPolicy::create(maxAttempts: 1, classifier: $classifier))
            ->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $pipeline->run(static function (): never {
                    throw new RuntimeException('caller cancellation');
                });
                self::fail('Operation failure was swallowed.');
            } catch (RuntimeException $failure) {
                self::assertSame('caller cancellation', $failure->getMessage());
            }
        }

        self::assertNull($store->get('cb:payments.charge'));
    }

    public function test_factory_reuses_same_named_breaker_and_bounds_shared_half_open_probes(): void
    {
        $clock = new FakeClock;
        $factory = new ResiliencePipelineFactory($clock, new InMemoryStateStore);
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10, halfOpenProbeLimit: 1);
        $first = $factory->pipeline('payments.charge')->withCircuitBreaker($policy);
        $second = $factory->pipeline('payments.charge')->withCircuitBreaker($policy);

        try {
            $first->run(static function (): never {
                throw new RuntimeException('provider down');
            });
            self::fail('Initial circuit failure was swallowed.');
        } catch (RuntimeException $failure) {
            self::assertSame('provider down', $failure->getMessage());
        }

        $clock->sleepMilliseconds(10);
        $probe = new \Fiber(static function () use ($first): string {
            return $first->run(static function (): string {
                \Fiber::suspend();

                return 'probe';
            });
        });
        $probe->start();
        $secondOperationCalled = false;

        try {
            $second->run(static function () use (&$secondOperationCalled): string {
                $secondOperationCalled = true;

                return 'over-admitted';
            });
            self::fail('A second named pipeline exceeded the half-open probe limit.');
        } catch (CircuitOpenException) {
            self::assertFalse($secondOperationCalled);
        } finally {
            $probe->resume();
        }

        self::assertSame('probe', $probe->getReturn());
    }

    public function test_rate_limiter_is_acquired_for_every_retry_attempt(): void
    {
        $clock = new FakeClock;
        $pipeline = (new ResiliencePipelineFactory($clock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withRateLimit(RateLimitPolicy::create(capacity: 1, refillPerSecond: 1, maxWaitMilliseconds: 1_000));
        $context = OperationContext::create('payments.charge', retryAllowed: true);
        $attempts = 0;

        self::assertSame('paid', $pipeline->run(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('transient');
            }

            return 'paid';
        }, $context));
        self::assertSame(2, $attempts);
        self::assertSame(1_000, $clock->nowMilliseconds());
    }

    public function test_bulkhead_slot_is_released_after_fallback_handles_failure(): void
    {
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withBulkhead(BulkheadPolicy::create(maxConcurrent: 1))
            ->withFallback(static fn (Throwable $failure, OperationContext $context): string => 'fallback');
        $context = OperationContext::create('payments.charge');

        self::assertSame('fallback', $pipeline->run(static function (): never {
            throw new RuntimeException('provider down');
        }, $context));
        self::assertSame('recovered', $pipeline->run(static fn (): string => 'recovered', $context));
    }

    public function test_fallback_runs_only_after_a_failure(): void
    {
        $fallbackCalls = 0;
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withFallback(static function () use (&$fallbackCalls): string {
                $fallbackCalls++;

                return 'fallback';
            });

        self::assertSame('paid', $pipeline->run(static fn (): string => 'paid'));
        self::assertSame(0, $fallbackCalls);
    }

    public function test_original_throwable_is_preserved_without_fallback(): void
    {
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))->pipeline('payments.charge');
        $failure = new LogicException('operation failed');

        try {
            $pipeline->run(static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Operation failure was swallowed.');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function test_fallback_failure_keeps_both_failures_available(): void
    {
        $original = new RuntimeException('operation failed');
        $fallbackFailure = new LogicException('fallback failed');
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withFallback(static function () use ($fallbackFailure): never {
                throw $fallbackFailure;
            });

        try {
            $pipeline->run(static function () use ($original): never {
                throw $original;
            });
            self::fail('Fallback failure was swallowed.');
        } catch (ResilienceFallbackException $caught) {
            self::assertSame($original, $caught->getPrevious());
            self::assertSame($fallbackFailure, $caught->fallbackFailure());
        }
    }

    private function retryPolicy(int $maxAttempts): RetryPolicy
    {
        return RetryPolicy::create(
            maxAttempts: $maxAttempts,
            backoffStrategy: FixedBackoff::create(),
            classifier: new class implements FailureClassifierInterface
            {
                public function classify(Throwable $failure, OperationContext $context): FailureDecision
                {
                    return FailureDecision::retryable();
                }
            },
        );
    }
}
