<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\Event\CircuitStateChanged;
use Tusk\Cloud\Resilience\Event\FallbackApplied;
use Tusk\Cloud\Resilience\Event\OperationRejected;
use Tusk\Cloud\Resilience\Event\OperationRejectionReason;
use Tusk\Cloud\Resilience\Event\RetryScheduled;
use Tusk\Cloud\Resilience\State;

final class ResilienceEventTest extends TestCase
{
    public function test_rejection_reasons_have_the_exact_wire_values(): void
    {
        self::assertSame([
            'CIRCUIT_OPEN' => 'circuit_open',
            'BULKHEAD_REJECTED' => 'bulkhead_rejected',
            'BULKHEAD_TIMEOUT' => 'bulkhead_timeout',
            'RATE_LIMIT_REJECTED' => 'rate_limit_rejected',
            'DEADLINE_EXCEEDED' => 'deadline_exceeded',
            'CANCELLED' => 'cancelled',
        ], array_column(OperationRejectionReason::cases(), 'value', 'name'));
    }

    public function test_event_payloads_are_public_readonly_values(): void
    {
        $retry = new RetryScheduled('payments.charge', 2, 150, 'RuntimeException');
        $fallback = new FallbackApplied('payments.charge', 'RuntimeException');
        $rejected = new OperationRejected('payments.charge', OperationRejectionReason::CIRCUIT_OPEN);
        $changed = new CircuitStateChanged('payments', State::CLOSED, State::OPEN);

        self::assertSame(['operation' => 'payments.charge', 'failedAttempt' => 2, 'delayMilliseconds' => 150, 'failureType' => 'RuntimeException'], get_object_vars($retry));
        self::assertSame(['operation' => 'payments.charge', 'failureType' => 'RuntimeException'], get_object_vars($fallback));
        self::assertSame(['operation' => 'payments.charge', 'reason' => OperationRejectionReason::CIRCUIT_OPEN], get_object_vars($rejected));
        self::assertSame(['operation' => 'payments', 'previous' => State::CLOSED, 'current' => State::OPEN], get_object_vars($changed));

        foreach ([$retry, $fallback, $rejected, $changed] as $event) {
            $reflection = new \ReflectionClass($event);
            self::assertTrue($reflection->isFinal());
            self::assertTrue($reflection->isReadOnly());
        }
    }

    public function test_retry_and_fallback_reject_blank_operation_or_failure_type(): void
    {
        foreach ([" \t\n "] as $operation) {
            try {
                new RetryScheduled($operation, 1, 0, 'RuntimeException');
                self::fail('Blank retry operation was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }

            try {
                new FallbackApplied($operation, 'RuntimeException');
                self::fail('Blank fallback operation was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        try {
            new RetryScheduled('payments', 1, 0, " \t\n ");
            self::fail('Blank retry failure type was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new FallbackApplied('payments', " \t\n ");
    }

    public function test_retry_rejects_attempts_below_one_and_negative_delays(): void
    {
        foreach ([[0, 0], [-1, 0], [1, -1]] as [$attempt, $delay]) {
            try {
                new RetryScheduled('payments', $attempt, $delay, 'RuntimeException');
                self::fail('Invalid retry scheduling values were accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_circuit_state_change_rejects_the_same_state(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CircuitStateChanged('payments', State::CLOSED, State::CLOSED);
    }
}
