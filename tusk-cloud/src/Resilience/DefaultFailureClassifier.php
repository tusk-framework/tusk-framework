<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Throwable;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class DefaultFailureClassifier implements FailureClassifierInterface
{
    public function classify(Throwable $failure, OperationContext $context): FailureDecision
    {
        return FailureDecision::terminal();
    }
}
