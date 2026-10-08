<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

interface CircuitBreakerInterface
{
    public function execute(
        callable $operation,
        OperationContext $context,
        CircuitBreakerPolicy $policy,
        ?FailureClassifierInterface $classifier = null,
    ): mixed;

    public function state(): State;

    /** @return array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: string} */
    public function snapshot(): array;
}
