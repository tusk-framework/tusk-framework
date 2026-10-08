<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Configuration;

use Throwable;
use Tusk\Cloud\Resilience\DefaultFailureClassifier;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class ConfiguredFailureClassifier implements FailureClassifierInterface
{
    /**
     * @param  list<class-string<Throwable>>  $retryOn
     * @param  list<class-string<Throwable>>  $doNotRetryOn
     */
    public function __construct(
        private array $retryOn,
        private array $doNotRetryOn,
        private DefaultFailureClassifier $defaultClassifier = new DefaultFailureClassifier,
    ) {}

    public function classify(Throwable $failure, OperationContext $context): FailureDecision
    {
        foreach ($this->doNotRetryOn as $class) {
            if ($failure instanceof $class) {
                return FailureDecision::terminal();
            }
        }

        if ($this->retryOn !== []) {
            foreach ($this->retryOn as $class) {
                if ($failure instanceof $class) {
                    return FailureDecision::retryable();
                }
            }

            return FailureDecision::terminal();
        }

        return $this->defaultClassifier->classify($failure, $context);
    }
}
