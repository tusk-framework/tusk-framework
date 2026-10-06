<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Deadline;
use Tusk\Cloud\Resilience\DefaultFailureClassifier;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\DeadlineInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class ContractAndClockTest extends TestCase
{
    public function test_fake_clock_advances_only_when_told_to_sleep(): void
    {
        $clock = new FakeClock(1_000);

        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertSame(1_000, $clock->nowMilliseconds());
        $clock->sleepMilliseconds(75);
        self::assertSame(1_075, $clock->nowMilliseconds());
        $clock->sleepMilliseconds(0);
        self::assertSame(1_075, $clock->nowMilliseconds());
    }

    public function test_fake_clock_rejects_negative_start_and_sleep(): void
    {
        foreach ([-1] as $start) {
            try {
                new FakeClock($start);
                self::fail('Negative start was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $clock = new FakeClock;
        $this->expectException(InvalidArgumentException::class);
        $clock->sleepMilliseconds(-1);
    }

    public function test_deadline_expires_at_boundary_and_remaining_time_never_goes_negative(): void
    {
        $deadline = new Deadline(1_100);
        $clock = new FakeClock(1_000);

        self::assertInstanceOf(DeadlineInterface::class, $deadline);
        self::assertFalse($deadline->isExpired($clock->nowMilliseconds()));
        self::assertSame(100, $deadline->remainingMilliseconds($clock->nowMilliseconds()));

        $clock->sleepMilliseconds(100);
        self::assertTrue($deadline->isExpired($clock->nowMilliseconds()));
        self::assertSame(0, $deadline->remainingMilliseconds($clock->nowMilliseconds()));

        $clock->sleepMilliseconds(1);
        self::assertSame(0, $deadline->remainingMilliseconds($clock->nowMilliseconds()));
    }

    public function test_deadline_rejects_negative_time_values(): void
    {
        try {
            new Deadline(-1);
            self::fail('Negative deadline was accepted.');
        } catch (InvalidArgumentException) {
        }

        $deadline = new Deadline(100);
        $this->expectException(InvalidArgumentException::class);
        $deadline->remainingMilliseconds(-1);
    }

    public function test_operation_context_has_safe_defaults_and_copies_metadata(): void
    {
        $metadata = ['request' => 'abc'];
        $deadline = new Deadline(1_100);
        $context = OperationContext::create(' payments.charge ', $deadline, true, $metadata);
        $metadata['request'] = 'changed';
        $received = $context->metadata();
        $received['request'] = 'changed again';

        self::assertSame('payments.charge', $context->operation());
        self::assertSame($deadline, $context->deadline());
        self::assertTrue($context->retryAllowed());
        self::assertSame(['request' => 'abc'], $context->metadata());

        $default = OperationContext::create('read');
        self::assertNull($default->deadline());
        self::assertFalse($default->retryAllowed());
        self::assertSame([], $default->metadata());
    }

    public function test_operation_context_rejects_blank_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationContext::create(" \t\n ");
    }

    public function test_default_classifier_makes_failures_terminal(): void
    {
        $failure = new RuntimeException('failed');
        $context = OperationContext::create('read');
        $decision = (new DefaultFailureClassifier)->classify($failure, $context);

        self::assertFalse($decision->isRetryable());
        self::assertTrue($decision->countsAsCircuitFailure());
    }

    public function test_failure_decision_can_distinguish_retry_and_circuit_accounting(): void
    {
        $retryable = FailureDecision::retryable(false);
        $terminal = FailureDecision::terminal(false);

        self::assertTrue($retryable->isRetryable());
        self::assertFalse($retryable->countsAsCircuitFailure());
        self::assertFalse($terminal->isRetryable());
        self::assertFalse($terminal->countsAsCircuitFailure());
    }

    public function test_system_clock_is_monotonic_and_implements_clock_contract(): void
    {
        $clock = new SystemClock;
        $first = $clock->nowMilliseconds();
        $second = $clock->nowMilliseconds();

        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertGreaterThanOrEqual(0, $first);
        self::assertGreaterThanOrEqual($first, $second);
    }
}
