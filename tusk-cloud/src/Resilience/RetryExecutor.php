<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Throwable;
use Tusk\Cloud\Resilience\Event\RetryScheduled;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class RetryExecutor
{
    public function __construct(
        private ClockInterface $clock,
        private ?ResilienceInstrumentation $instrumentation = null,
    ) {}

    public function execute(callable $operation, OperationContext $context, RetryPolicy $policy): mixed
    {
        $previousFailure = null;
        $previousDelay = 0;

        for ($attempt = 1; $attempt <= $policy->maxAttempts(); $attempt++) {
            $this->assertNotCancelled($context);
            $this->assertWithinDeadline($context, $previousFailure);

            try {
                return $operation($context);
            } catch (Throwable $failure) {
                if (! $policy->classifier()->classify($failure, $context)->isRetryable()
                    || ! $policy->canRetryAfter($attempt, $context)) {
                    throw $failure;
                }

                $delay = $policy->backoffStrategy()->delayMilliseconds($attempt, $previousDelay);
                $this->assertNotCancelled($context);
                $this->assertWithinDeadline($context, $failure, $delay);
                try {
                    $this->instrumentation?->retryScheduled(new RetryScheduled($context->operation(), $attempt, $delay, $failure::class));
                } catch (Throwable) {
                    // Event validation must not change custom backoff behavior.
                }
                $this->sleepWithCancellation($delay, $context, $failure);
                $previousDelay = $delay;
                $previousFailure = $failure;
            }
        }

        // canRetryAfter() prevents the loop from advancing past its last attempt.
        throw new \LogicException('Retry execution reached an unreachable state.');
    }

    private function sleepWithCancellation(int $milliseconds, OperationContext $context, ?Throwable $previousFailure): void
    {
        $remaining = $milliseconds;
        while ($remaining > 0) {
            $this->assertNotCancelled($context);
            $slice = min(10, $remaining);
            $this->clock->sleepMilliseconds($slice);
            $remaining -= $slice;
            $this->assertWithinDeadline($context, $previousFailure);
        }
    }

    private function assertNotCancelled(OperationContext $context): void
    {
        if ($context->isCancellationRequested()) {
            throw new OperationCancelledException($context->operation());
        }
    }

    private function assertWithinDeadline(OperationContext $context, ?Throwable $previousFailure, int $delay = 0): void
    {
        $deadline = $context->deadline();
        if ($deadline === null) {
            return;
        }

        $now = $this->clock->nowMilliseconds();
        if ($deadline->isExpired($now) || $delay > $deadline->remainingMilliseconds($now)) {
            throw new ResilienceDeadlineExceededException($context->operation(), $previousFailure);
        }
    }
}
