<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Throwable;
use Tusk\Cloud\Resilience\Exception\OperationCancelledException;
use Tusk\Cloud\Resilience\Exception\RateLimitRejectedException;
use Tusk\Cloud\Resilience\Exception\ResilienceDeadlineExceededException;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class DefaultFailureClassifier implements FailureClassifierInterface
{
    public function classify(Throwable $failure, OperationContext $context): FailureDecision
    {
        if ($failure instanceof OperationCancelledException
            || $failure instanceof RateLimitRejectedException
            || $failure instanceof ResilienceDeadlineExceededException) {
            return FailureDecision::terminal(circuitFailure: false);
        }

        return FailureDecision::terminal();
    }
}
