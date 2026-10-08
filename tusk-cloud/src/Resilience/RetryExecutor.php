<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Throwable;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class RetryExecutor
{
    public function __construct(private ClockInterface $clock) {}

    public function execute(callable $operation, OperationContext $context, RetryPolicy $policy): mixed
    {
        $previousFailure = null;
        $previousDelay = 0;

        for ($attempt = 1; $attempt <= $policy->maxAttempts(); $attempt++) {
            $this->assertWithinDeadline($context, $previousFailure);

            try {
                return $operation($context);
            } catch (Throwable $failure) {
                if (! $policy->classifier()->classify($failure, $context)->isRetryable()
                    || ! $policy->canRetryAfter($attempt, $context)) {
                    throw $failure;
                }

                $delay = $policy->backoffStrategy()->delayMilliseconds($attempt, $previousDelay);
                $this->assertWithinDeadline($context, $failure, $delay);
                $this->clock->sleepMilliseconds($delay);
                $previousDelay = $delay;
                $previousFailure = $failure;
            }
        }

        // canRetryAfter() prevents the loop from advancing past its last attempt.
        throw new \LogicException('Retry execution reached an unreachable state.');
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
