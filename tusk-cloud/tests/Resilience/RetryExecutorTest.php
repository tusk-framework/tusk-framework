<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\BackoffStrategyInterface;
use Tusk\Cloud\Resilience\Deadline;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Cloud\Resilience\RetryExecutor;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\CancellationTokenInterface;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class RetryExecutorTest extends TestCase
{
    public function test_cancellation_stops_retries_before_the_next_attempt(): void
    {
        $state = (object) ['cancelled' => false];
        $token = new class($state) implements CancellationTokenInterface
        {
            public function __construct(private object $state) {}

            public function isCancellationRequested(): bool
            {
                return $this->state->cancelled;
            }
        };
        $classifier = new class($state) implements FailureClassifierInterface
        {
            public function __construct(private object $state) {}

            public function classify(Throwable $failure, OperationContext $context): FailureDecision
            {
                $this->state->cancelled = true;

                return FailureDecision::retryable();
            }
        };
        $attempts = 0;

        try {
            (new RetryExecutor(new FakeClock))->execute(static function () use (&$attempts): never {
                $attempts++;
                throw new RuntimeException('temporary');
            }, OperationContext::create('read', retryAllowed: true, cancellationToken: $token), RetryPolicy::create(maxAttempts: 3, classifier: $classifier));
            self::fail('Cancellation did not stop the retry loop.');
        } catch (OperationCancelledException) {
            self::assertSame(1, $attempts);
        }
    }

    public function test_first_attempt_returns_result_without_sleeping(): void
    {
        $clock = new FakeClock(100);
        $context = OperationContext::create('read');
        $attempts = 0;

        $result = (new RetryExecutor($clock))->execute(function (OperationContext $received) use ($context, &$attempts): string {
            self::assertSame($context, $received);
            $attempts++;

            return 'ok';
        }, $context, RetryPolicy::create(maxAttempts: 1));

        self::assertSame('ok', $result);
        self::assertSame(1, $attempts);
        self::assertSame(100, $clock->nowMilliseconds());
    }

    public function test_retryable_failure_retries_with_one_based_backoff_and_previous_delay(): void
    {
        $clock = new FakeClock;
        $context = OperationContext::create('read', retryAllowed: true);
        $attempts = 0;
        $requests = [];
        $backoff = new class($requests) implements BackoffStrategyInterface
        {
            public function __construct(private array &$requests) {}

            public function delayMilliseconds(int $retryNumber, int $previousDelayMilliseconds): int
            {
                $this->requests[] = [$retryNumber, $previousDelayMilliseconds];

                return $retryNumber * 10;
            }
        };

        $result = (new RetryExecutor($clock))->execute(function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('temporary');
            }

            return 'recovered';
        }, $context, RetryPolicy::create(maxAttempts: 3, backoffStrategy: $backoff, classifier: $this->retryableClassifier()));

        self::assertSame('recovered', $result);
        self::assertSame(3, $attempts);
        self::assertSame([[1, 0], [2, 10]], $requests);
        self::assertSame(30, $clock->nowMilliseconds());
    }

    public function test_terminal_failure_rethrows_the_same_throwable_without_backoff(): void
    {
        $failure = new RuntimeException('terminal');
        $clock = new FakeClock;
        $attempts = 0;

        try {
            (new RetryExecutor($clock))->execute(function () use ($failure, &$attempts): never {
                $attempts++;
                throw $failure;
            }, OperationContext::create('read', retryAllowed: true), RetryPolicy::create(maxAttempts: 3));
            self::fail('Terminal failure was swallowed.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $attempts);
        self::assertSame(0, $clock->nowMilliseconds());
    }

    public function test_exhausted_attempts_rethrow_the_last_original_throwable(): void
    {
        $first = new RuntimeException('first');
        $last = new RuntimeException('last');
        $attempts = 0;

        try {
            (new RetryExecutor(new FakeClock))->execute(function () use ($first, $last, &$attempts): never {
                $attempts++;
                throw $attempts === 1 ? $first : $last;
            }, OperationContext::create('read', retryAllowed: true), RetryPolicy::create(maxAttempts: 2, classifier: $this->retryableClassifier()));
            self::fail('Exhausted failure was swallowed.');
        } catch (RuntimeException $caught) {
            self::assertSame($last, $caught);
        }

        self::assertSame(2, $attempts);
    }

    public function test_unsafe_operation_is_not_retried_without_policy_opt_in(): void
    {
        $attempts = 0;
        $failure = new RuntimeException('write failed');

        try {
            (new RetryExecutor(new FakeClock))->execute(function () use (&$attempts, $failure): never {
                $attempts++;
                throw $failure;
            }, OperationContext::create('write'), RetryPolicy::create(maxAttempts: 3, classifier: $this->retryableClassifier()));
            self::fail('Unsafe retry was allowed.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $attempts);
    }

    public function test_policy_can_explicitly_allow_unsafe_retry(): void
    {
        $attempts = 0;
        $result = (new RetryExecutor(new FakeClock))->execute(function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('temporary');
            }

            return 'saved';
        }, OperationContext::create('write'), RetryPolicy::create(maxAttempts: 2, classifier: $this->retryableClassifier(), allowUnsafeRetries: true));

        self::assertSame('saved', $result);
        self::assertSame(2, $attempts);
    }

    public function test_deadline_equal_to_now_blocks_first_attempt(): void
    {
        $attempts = 0;
        $context = OperationContext::create('read', new Deadline(100), retryAllowed: true);

        try {
            (new RetryExecutor(new FakeClock(100)))->execute(function () use (&$attempts): void {
                $attempts++;
            }, $context, RetryPolicy::create());
            self::fail('Expired operation was executed.');
        } catch (ResilienceDeadlineExceededException $caught) {
            self::assertSame('read', $caught->operation());
            self::assertNull($caught->getPrevious());
        }

        self::assertSame(0, $attempts);
    }

    public function test_backoff_exceeding_remaining_deadline_preserves_failure(): void
    {
        $clock = new FakeClock(100);
        $failure = new RuntimeException('temporary');
        $attempts = 0;

        try {
            (new RetryExecutor($clock))->execute(function () use (&$attempts, $failure): never {
                $attempts++;
                throw $failure;
            }, OperationContext::create('read', new Deadline(109), retryAllowed: true), RetryPolicy::create(maxAttempts: 3, backoffStrategy: FixedBackoff::create(10), classifier: $this->retryableClassifier()));
            self::fail('Backoff exceeded the deadline.');
        } catch (ResilienceDeadlineExceededException $caught) {
            self::assertSame('read', $caught->operation());
            self::assertSame($failure, $caught->getPrevious());
        }

        self::assertSame(1, $attempts);
        self::assertSame(100, $clock->nowMilliseconds());
    }

    public function test_backoff_stops_if_the_clock_oversleeps_past_the_deadline(): void
    {
        $clock = new class implements ClockInterface
        {
            private FakeClock $inner;

            public function __construct()
            {
                $this->inner = new FakeClock;
            }

            public function nowMilliseconds(): int
            {
                return $this->inner->nowMilliseconds();
            }

            public function sleepMilliseconds(int $milliseconds): void
            {
                $this->inner->sleepMilliseconds($milliseconds + 1);
            }
        };
        $failure = new RuntimeException('temporary');
        $attempts = 0;

        try {
            (new RetryExecutor($clock))->execute(static function () use (&$attempts, $failure): never {
                $attempts++;
                throw $failure;
            }, OperationContext::create('read', new Deadline(25), retryAllowed: true), RetryPolicy::create(
                maxAttempts: 2,
                backoffStrategy: FixedBackoff::create(25),
                classifier: $this->retryableClassifier(),
            ));
            self::fail('Retry continued after the operation deadline.');
        } catch (ResilienceDeadlineExceededException $caught) {
            self::assertSame($failure, $caught->getPrevious());
            self::assertSame(1, $attempts);
        }
    }

    public function test_deadline_rechecked_before_next_attempt_with_prior_failure(): void
    {
        $clock = new FakeClock(100);
        $failure = new RuntimeException('temporary');
        $attempts = 0;

        try {
            (new RetryExecutor($clock))->execute(function () use (&$attempts, $failure): never {
                $attempts++;
                throw $failure;
            }, OperationContext::create('read', new Deadline(110), retryAllowed: true), RetryPolicy::create(maxAttempts: 2, backoffStrategy: FixedBackoff::create(10), classifier: $this->retryableClassifier()));
            self::fail('Attempt ran at the deadline.');
        } catch (ResilienceDeadlineExceededException $caught) {
            self::assertSame($failure, $caught->getPrevious());
        }

        self::assertSame(1, $attempts);
        self::assertSame(110, $clock->nowMilliseconds());
    }

    public function test_operation_and_classifier_receive_the_identical_context(): void
    {
        $context = OperationContext::create('read', retryAllowed: true, metadata: ['trace' => 'abc']);
        $failure = new RuntimeException('temporary');
        $classifiedContext = null;
        $classifier = new class($classifiedContext) implements FailureClassifierInterface
        {
            public function __construct(private ?OperationContext &$classifiedContext) {}

            public function classify(Throwable $failure, OperationContext $context): FailureDecision
            {
                $this->classifiedContext = $context;

                return FailureDecision::retryable();
            }
        };
        $attempts = 0;

        $result = (new RetryExecutor(new FakeClock))->execute(function (OperationContext $received) use ($context, $failure, &$attempts): string {
            self::assertSame($context, $received);
            if (++$attempts === 1) {
                throw $failure;
            }

            return 'ok';
        }, $context, RetryPolicy::create(maxAttempts: 2, classifier: $classifier));

        self::assertSame('ok', $result);
        self::assertSame($context, $classifiedContext);
    }

    private function retryableClassifier(): FailureClassifierInterface
    {
        return new class implements FailureClassifierInterface
        {
            public function classify(Throwable $failure, OperationContext $context): FailureDecision
            {
                return FailureDecision::retryable();
            }
        };
    }
}
