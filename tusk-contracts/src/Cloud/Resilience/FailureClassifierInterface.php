<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

use Throwable;

interface FailureClassifierInterface
{
    public function classify(Throwable $failure, OperationContext $context): FailureDecision;
}
