<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use Fiber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Bulkhead;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\Deadline;
use Tusk\Cloud\Resilience\Exception\BulkheadRejectedException;
use Tusk\Cloud\Resilience\Exception\BulkheadTimeoutException;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class BulkheadTest extends TestCase
{
    public function test_full_bulkhead_rejects_immediately_without_invoking_operation(): void
    {
        $bulkhead = new Bulkhead(new FakeClock);
        $context = OperationContext::create('charge');
        $policy = BulkheadPolicy::create(maxConcurrent: 1);
        $holder = new Fiber(fn (): mixed => $bulkhead->run(static function (): void {
            Fiber::suspend();
        }, $context, $policy));
        $holder->start();
        $invoked = false;

        try {
            $bulkhead->run(function () use (&$invoked): void {
                $invoked = true;
            }, $context, $policy);
            self::fail('A full bulkhead admitted work.');
        } catch (BulkheadRejectedException) {
            self::assertFalse($invoked);
        } finally {
            $holder->resume();
        }

        self::assertSame('free', $bulkhead->run(static fn (): string => 'free', $context, $policy));
    }

    public function test_bounded_fiber_queue_admits_after_slot_is_released_and_rejects_excess_waiter(): void
    {
        $clock = $this->suspendingClock();
        $bulkhead = new Bulkhead($clock);
        $context = OperationContext::create('charge', new Deadline(10));
        $policy = BulkheadPolicy::create(maxConcurrent: 1, maxQueued: 1);
        $holder = new Fiber(fn (): mixed => $bulkhead->run(static function (): void {
            Fiber::suspend();
        }, $context, $policy));
        $holder->start();
        $queued = new Fiber(fn (): mixed => $bulkhead->run(static fn (): string => 'queued work', $context, $policy));
        $queued->start();
        self::assertTrue($queued->isSuspended());

        try {
            $bulkhead->run(static fn (): string => 'overflow', $context, $policy);
            self::fail('A second waiter entered the bounded queue.');
        } catch (BulkheadRejectedException) {
        }

        $holder->resume();
        $queued->resume();
        self::assertSame('queued work', $queued->getReturn());
        self::assertSame('next', $bulkhead->run(static fn (): string => 'next', $context, $policy));
    }

    public function test_queued_caller_times_out_at_deadline_and_frees_queue_capacity(): void
    {
        $clock = $this->suspendingClock();
        $bulkhead = new Bulkhead($clock);
        $policy = BulkheadPolicy::create(maxConcurrent: 1, maxQueued: 1);
        $holder = new Fiber(fn (): mixed => $bulkhead->run(static function (): void {
            Fiber::suspend();
        }, OperationContext::create('holder'), $policy));
        $holder->start();
        $queued = new Fiber(fn (): mixed => $bulkhead->run(static fn (): string => 'late', OperationContext::create('queued', new Deadline(1)), $policy));
        $queued->start();
        $clock->sleepMilliseconds(1);

        try {
            $queued->resume();
            self::fail('Expired queued operation ran.');
        } catch (BulkheadTimeoutException) {
        }

        $second = new Fiber(fn (): mixed => $bulkhead->run(static fn (): string => 'second', OperationContext::create('second', new Deadline(10)), $policy));
        $second->start();
        self::assertTrue($second->isSuspended());
        $holder->resume();
        $second->resume();
        self::assertSame('second', $second->getReturn());
    }

    public function test_operation_throwable_releases_admitted_slot_and_preserves_identity(): void
    {
        $bulkhead = new Bulkhead(new FakeClock);
        $context = OperationContext::create('charge');
        $policy = BulkheadPolicy::create();
        $failure = new RuntimeException('operation failed');

        try {
            $bulkhead->run(static function () use ($failure): never {
                throw $failure;
            }, $context, $policy);
            self::fail('Operation failure was swallowed.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame('reused', $bulkhead->run(static fn (): string => 'reused', $context, $policy));
    }

    public function test_expired_deadline_does_not_run_operation_or_consume_a_slot(): void
    {
        $bulkhead = new Bulkhead(new FakeClock(10));
        $policy = BulkheadPolicy::create();
        $invoked = false;

        try {
            $bulkhead->run(function () use (&$invoked): void {
                $invoked = true;
            }, OperationContext::create('expired', new Deadline(10)), $policy);
            self::fail('Expired operation was admitted.');
        } catch (BulkheadTimeoutException) {
            self::assertFalse($invoked);
        }

        self::assertSame('free', $bulkhead->run(static fn (): string => 'free', OperationContext::create('free'), $policy));
    }

    public function test_bulkhead_instance_cannot_mix_concurrency_policies(): void
    {
        $bulkhead = new Bulkhead(new FakeClock);
        $bulkhead->run(static fn (): null => null, OperationContext::create('first'), BulkheadPolicy::create(maxConcurrent: 1));

        $this->expectException(InvalidArgumentException::class);
        $bulkhead->run(static fn (): null => null, OperationContext::create('second'), BulkheadPolicy::create(maxConcurrent: 2));
    }

    public function test_clock_failure_while_queued_propagates_and_frees_queue_capacity(): void
    {
        $clock = new class implements ClockInterface
        {
            public function nowMilliseconds(): int
            {
                return 0;
            }

            public function sleepMilliseconds(int $milliseconds): void
            {
                throw new RuntimeException('clock failed');
            }
        };
        $bulkhead = new Bulkhead($clock);
        $policy = BulkheadPolicy::create(maxConcurrent: 1, maxQueued: 1);
        $holder = new Fiber(fn (): mixed => $bulkhead->run(static function (): void {
            Fiber::suspend();
        }, OperationContext::create('holder'), $policy));
        $holder->start();
        $context = OperationContext::create('queued', new Deadline(10));

        for ($i = 0; $i < 2; $i++) {
            try {
                $bulkhead->run(static fn (): string => 'not run', $context, $policy);
                self::fail('Clock failure was swallowed.');
            } catch (RuntimeException $caught) {
                self::assertSame('clock failed', $caught->getMessage());
            }
        }

        $holder->resume();
    }

    private function suspendingClock(): ClockInterface
    {
        return new class implements ClockInterface
        {
            private FakeClock $clock;

            public function __construct()
            {
                $this->clock = new FakeClock;
            }

            public function nowMilliseconds(): int
            {
                return $this->clock->nowMilliseconds();
            }

            public function sleepMilliseconds(int $milliseconds): void
            {
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                }

                $this->clock->sleepMilliseconds($milliseconds);
            }
        };
    }
}
