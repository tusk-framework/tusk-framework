<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use Fiber;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\CircuitBreaker;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\State;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

final class CircuitBreakerTest extends TestCase
{
    public function test_closed_success_returns_result_and_keeps_a_serializable_snapshot(): void
    {
        $store = new InMemoryStateStore;
        $breaker = new CircuitBreaker($store, new FakeClock(100), 'payments');
        $context = OperationContext::create('charge');

        self::assertSame('paid', $breaker->execute(function (OperationContext $received) use ($context): string {
            self::assertSame($context, $received);

            return 'paid';
        }, $context, CircuitBreakerPolicy::create()));
        self::assertSame(State::CLOSED, $breaker->state());
        self::assertSame($this->closedSnapshot($breaker), $breaker->snapshot());
        self::assertSame($breaker->snapshot(), $store->get('cb:payments'));
        self::assertSame($breaker->snapshot(), unserialize(serialize($breaker->snapshot())));
    }

    public function test_consecutive_failures_open_at_threshold_using_injected_clock(): void
    {
        $clock = new FakeClock(100);
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 2);
        $context = OperationContext::create('charge');
        $failure = new RuntimeException('provider failed');

        $this->catchSameFailure($breaker, $context, $policy, $failure);
        self::assertSame(['state' => 'CLOSED', 'failureCount' => 1, 'openedAtMilliseconds' => null, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, 0)], $breaker->snapshot());
        $clock->sleepMilliseconds(7);
        $this->catchSameFailure($breaker, $context, $policy, $failure);
        self::assertSame(State::OPEN, $breaker->state());
        self::assertSame(['state' => 'OPEN', 'failureCount' => 2, 'openedAtMilliseconds' => 107, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, 0)], $breaker->snapshot());
    }

    public function test_closed_success_resets_consecutive_failures(): void
    {
        $breaker = new CircuitBreaker(new InMemoryStateStore, new FakeClock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 2);
        $context = OperationContext::create('charge');

        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('first'));
        self::assertSame('ok', $breaker->execute(fn (): string => 'ok', $context, $policy));
        self::assertSame($this->closedSnapshot($breaker), $breaker->snapshot());
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('second'));
        self::assertSame(State::CLOSED, $breaker->state());
        self::assertSame(1, $breaker->snapshot()['failureCount']);
    }

    public function test_open_rejects_without_invoking_operation_or_mutating_snapshot(): void
    {
        $breaker = new CircuitBreaker(new InMemoryStateStore, new FakeClock(100), 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 50);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('down'));
        $before = $breaker->snapshot();
        $called = false;

        try {
            $breaker->execute(function () use (&$called): void {
                $called = true;
            }, $context, $policy);
            self::fail('Open circuit admitted the call.');
        } catch (CircuitOpenException) {
            self::assertFalse($called);
        }

        self::assertSame($before, $breaker->snapshot());
    }

    public function test_open_duration_equality_admits_a_half_open_probe(): void
    {
        $clock = new FakeClock(100);
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 50);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('down'));
        $clock->sleepMilliseconds(49);
        $this->expectOpen($breaker, $context, $policy);
        $clock->sleepMilliseconds(1);

        self::assertSame('recovered', $breaker->execute(function () use ($breaker): string {
            self::assertSame(State::HALF_OPEN, $breaker->state());
            self::assertSame(1, $breaker->snapshot()['halfOpenProbeCount']);

            return 'recovered';
        }, $context, $policy));
        self::assertSame($this->closedSnapshot($breaker, 1), $breaker->snapshot());
    }

    public function test_failed_probe_reopens_and_restarts_open_duration(): void
    {
        $clock = new FakeClock(100);
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 50);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('first'));
        $clock->sleepMilliseconds(50);
        $failure = new RuntimeException('probe failed');

        $this->catchSameFailure($breaker, $context, $policy, $failure);
        self::assertSame(['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 150, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, 1)], $breaker->snapshot());
        $this->expectOpen($breaker, $context, $policy);
        $clock->sleepMilliseconds(50);
        self::assertSame('ok', $breaker->execute(fn (): string => 'ok', $context, $policy));
    }

    public function test_half_open_probe_limit_rejects_recursive_call_without_changing_admission(): void
    {
        $clock = new FakeClock;
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('down'));
        $clock->sleepMilliseconds(10);

        self::assertSame('outer', $breaker->execute(function () use ($breaker, $context, $policy): string {
            $before = $breaker->snapshot();
            $this->expectOpen($breaker, $context, $policy);
            self::assertSame($before, $breaker->snapshot());

            return 'outer';
        }, $context, $policy));
        self::assertSame($this->closedSnapshot($breaker, 1), $breaker->snapshot());
    }

    public function test_configured_probe_limit_allows_only_that_many_in_flight(): void
    {
        $clock = new FakeClock;
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10, halfOpenProbeLimit: 2);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('down'));
        $clock->sleepMilliseconds(10);

        self::assertSame('outer', $breaker->execute(function () use ($breaker, $context, $policy): string {
            self::assertSame('inner', $breaker->execute(function () use ($breaker, $context, $policy): string {
                self::assertSame(2, $breaker->snapshot()['halfOpenProbeCount']);
                $this->expectOpen($breaker, $context, $policy);

                return 'inner';
            }, $context, $policy));

            return 'outer';
        }, $context, $policy));
        self::assertSame($this->closedSnapshot($breaker, 1), $breaker->snapshot());
    }

    public function test_late_failed_probe_reopens_after_another_probe_succeeds(): void
    {
        $clock = new FakeClock(100);
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10, halfOpenProbeLimit: 2);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('down'));
        $clock->sleepMilliseconds(10);
        $lateFailure = new RuntimeException('late probe failed');

        $this->catchSameFailure($breaker, $context, $policy, $lateFailure, function () use ($breaker, $context, $policy, $lateFailure): never {
            self::assertSame('inner ok', $breaker->execute(fn (): string => 'inner ok', $context, $policy));
            self::assertSame(State::CLOSED, $breaker->state());
            throw $lateFailure;
        });
        self::assertSame(['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 110, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, 1)], $breaker->snapshot());
    }

    public function test_stale_success_cannot_close_a_later_half_open_round(): void
    {
        [$breaker, $oldProbe, $newProbe, $oldGeneration] = $this->startLaterRoundWithOldProbe(static fn (): string => 'old success');
        $before = $breaker->snapshot();
        self::assertSame($this->generation($breaker, 1), $oldGeneration);
        self::assertSame($this->generation($breaker, 2), $before['halfOpenGeneration']);

        $oldProbe->resume();
        self::assertSame('old success', $oldProbe->getReturn());
        self::assertSame($before, $breaker->snapshot());

        $newProbe->resume();
        self::assertSame('new success', $newProbe->getReturn());
        self::assertSame(State::CLOSED, $breaker->state());
    }

    public function test_stale_failure_cannot_reopen_a_later_half_open_round(): void
    {
        $oldFailure = new LogicException('old probe failed');
        [$breaker, $oldProbe, $newProbe] = $this->startLaterRoundWithOldProbe(static function () use ($oldFailure): never {
            throw $oldFailure;
        });
        $before = $breaker->snapshot();

        try {
            $oldProbe->resume();
            self::fail('Old probe failure was swallowed.');
        } catch (LogicException $caught) {
            self::assertSame($oldFailure, $caught);
        }
        self::assertSame($before, $breaker->snapshot());

        $newProbe->resume();
        self::assertSame('new success', $newProbe->getReturn());
        self::assertSame(State::CLOSED, $breaker->state());
    }

    public function test_stale_probe_cannot_close_a_new_round_after_legacy_record_replaces_state(): void
    {
        $store = new InMemoryStateStore;
        $clock = new FakeClock;
        $breaker = new CircuitBreaker($store, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('first outage'));
        $clock->sleepMilliseconds(10);

        $oldProbe = new Fiber(static fn (): string => $breaker->execute(static function (): string {
            Fiber::suspend();

            return 'old success';
        }, $context, $policy));
        $oldProbe->start();
        self::assertTrue($oldProbe->isSuspended());

        $store->set('cb:payments', ['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 0, 'halfOpenProbeCount' => 0]);
        self::assertSame(State::CLOSED, $breaker->state());
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('second outage'));
        $clock->sleepMilliseconds(10);

        $newProbe = new Fiber(static fn (): string => $breaker->execute(static function (): string {
            Fiber::suspend();

            return 'new success';
        }, $context, $policy));
        $newProbe->start();
        self::assertTrue($newProbe->isSuspended());
        $before = $breaker->snapshot();
        self::assertSame(State::HALF_OPEN->value, $before['state']);
        self::assertSame(1, $before['halfOpenProbeCount']);

        $oldProbe->resume();
        self::assertSame('old success', $oldProbe->getReturn());
        self::assertSame($before, $breaker->snapshot());

        $newProbe->resume();
        self::assertSame('new success', $newProbe->getReturn());
        self::assertSame(State::CLOSED, $breaker->state());
    }

    public function test_outcome_store_get_failure_preserves_original_throwable(): void
    {
        $this->assertOriginalFailureSurvivesStoreFailure('get');
    }

    public function test_outcome_store_set_failure_preserves_original_throwable(): void
    {
        $this->assertOriginalFailureSurvivesStoreFailure('set');
    }

    public function test_legacy_or_malformed_generation_is_treated_as_closed(): void
    {
        $store = new InMemoryStateStore;
        $breaker = new CircuitBreaker($store, new FakeClock, 'payments');

        foreach ([
            ['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 0, 'halfOpenProbeCount' => 0],
            ['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 0, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => 'old'],
        ] as $record) {
            $store->set('cb:payments', $record);
            self::assertSame(State::CLOSED, $breaker->state());
            self::assertSame($this->closedSnapshot($breaker), $breaker->snapshot());
        }
    }

    public function test_exhausted_generation_rejects_probe_without_overflow_or_invoking_operation(): void
    {
        $store = new InMemoryStateStore;
        $clock = new FakeClock(10);
        $breaker = new CircuitBreaker($store, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10);
        $record = ['state' => 'OPEN', 'failureCount' => 1, 'openedAtMilliseconds' => 0, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, PHP_INT_MAX)];
        $store->set('cb:payments', $record);
        $called = false;

        try {
            $breaker->execute(function () use (&$called): void {
                $called = true;
            }, OperationContext::create('charge'), $policy);
            self::fail('Exhausted generation admitted a probe.');
        } catch (CircuitOpenException) {
            self::assertFalse($called);
        }

        self::assertSame($record, $breaker->snapshot());
    }

    public function test_missing_and_malformed_store_records_are_treated_as_closed(): void
    {
        $store = new InMemoryStateStore;
        $breaker = new CircuitBreaker($store, new FakeClock, 'payments');
        $policy = CircuitBreakerPolicy::create();
        $context = OperationContext::create('charge');
        self::assertSame($this->closedSnapshot($breaker), $breaker->snapshot());

        foreach ([['state' => 'OPEN'], ['state' => 'UNKNOWN', 'failureCount' => 0, 'openedAtMilliseconds' => 0, 'halfOpenProbeCount' => 0], ['state' => 'OPEN', 'failureCount' => -1, 'openedAtMilliseconds' => 'yesterday', 'halfOpenProbeCount' => 0]] as $bad) {
            $store->set('cb:payments', $bad);
            self::assertSame(State::CLOSED, $breaker->state());
            self::assertSame('ok', $breaker->execute(fn (): string => 'ok', $context, $policy));
            self::assertSame($this->closedSnapshot($breaker), $breaker->snapshot());
        }
    }

    public function test_named_breakers_keep_independent_snapshots_in_shared_store(): void
    {
        $store = new InMemoryStateStore;
        $clock = new FakeClock(20);
        $first = new CircuitBreaker($store, $clock, 'payments');
        $second = new CircuitBreaker($store, $clock, 'shipping');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1);
        $context = OperationContext::create('charge');

        $this->catchSameFailure($first, $context, $policy, new RuntimeException('down'));
        self::assertSame(State::OPEN, $first->state());
        self::assertSame(State::CLOSED, $second->state());
        self::assertSame('ok', $second->execute(fn (): string => 'ok', $context, $policy));
        self::assertSame($first->snapshot(), $store->get('cb:payments'));
        self::assertSame($second->snapshot(), $store->get('cb:shipping'));
    }

    public function test_blank_circuit_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CircuitBreaker(new InMemoryStateStore, new FakeClock, '  ');
    }

    private function catchSameFailure(CircuitBreaker $breaker, OperationContext $context, CircuitBreakerPolicy $policy, RuntimeException $failure, ?callable $operation = null): void
    {
        try {
            $breaker->execute($operation ?? static function () use ($failure): never {
                throw $failure;
            }, $context, $policy);
            self::fail('Operation failure was swallowed.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    private function expectOpen(CircuitBreaker $breaker, OperationContext $context, CircuitBreakerPolicy $policy): void
    {
        try {
            $breaker->execute(static fn (): string => 'unexpected', $context, $policy);
            self::fail('Circuit admitted an extra call.');
        } catch (CircuitOpenException) {
        }
    }

    /** @return array{CircuitBreaker, Fiber, Fiber, string} */
    private function startLaterRoundWithOldProbe(callable $oldCompletion): array
    {
        $clock = new FakeClock;
        $breaker = new CircuitBreaker(new InMemoryStateStore, $clock, 'payments');
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10, halfOpenProbeLimit: 2);
        $context = OperationContext::create('charge');
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('first outage'));
        $clock->sleepMilliseconds(10);

        $oldProbe = new Fiber(static fn (): mixed => $breaker->execute(static function () use ($oldCompletion): mixed {
            Fiber::suspend();

            return $oldCompletion();
        }, $context, $policy));
        $oldProbe->start();
        self::assertTrue($oldProbe->isSuspended());
        $oldGeneration = $breaker->snapshot()['halfOpenGeneration'];

        self::assertSame('first recovery', $breaker->execute(static fn (): string => 'first recovery', $context, $policy));
        $this->catchSameFailure($breaker, $context, $policy, new RuntimeException('second outage'));
        $clock->sleepMilliseconds(10);

        $newProbe = new Fiber(static fn (): string => $breaker->execute(static function (): string {
            Fiber::suspend();

            return 'new success';
        }, $context, $policy));
        $newProbe->start();
        self::assertTrue($newProbe->isSuspended());
        self::assertSame(State::HALF_OPEN, $breaker->state());

        return [$breaker, $oldProbe, $newProbe, $oldGeneration];
    }

    private function assertOriginalFailureSurvivesStoreFailure(string $failurePoint): void
    {
        $store = new class($failurePoint) implements StateStoreInterface
        {
            private int $reads = 0;

            public function __construct(private readonly string $failurePoint) {}

            public function get(string $key): ?array
            {
                if (++$this->reads === 2 && $this->failurePoint === 'get') {
                    throw new RuntimeException('store read failed');
                }

                return null;
            }

            public function set(string $key, array $data, ?float $ttl = null): void
            {
                if ($this->failurePoint === 'set') {
                    throw new RuntimeException('store write failed');
                }
            }
        };
        $breaker = new CircuitBreaker($store, new FakeClock, 'payments');
        $failure = new LogicException('operation failed');

        try {
            $breaker->execute(static function () use ($failure): never {
                throw $failure;
            }, OperationContext::create('charge'), CircuitBreakerPolicy::create());
            self::fail('Operation failure was swallowed.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }
    }

    private function generation(CircuitBreaker $breaker, int $counter): string
    {
        $namespace = explode(':', $breaker->snapshot()['halfOpenGeneration'], 2)[0];

        return "{$namespace}:{$counter}";
    }

    /** @return array{state: string, failureCount: int, openedAtMilliseconds: null, halfOpenProbeCount: int, halfOpenGeneration: string} */
    private function closedSnapshot(CircuitBreaker $breaker, int $generation = 0): array
    {
        return ['state' => 'CLOSED', 'failureCount' => 0, 'openedAtMilliseconds' => null, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $this->generation($breaker, $generation)];
    }
}
