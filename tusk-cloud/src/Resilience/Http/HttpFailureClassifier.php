<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Throwable;
use Tusk\Cloud\Resilience\DefaultFailureClassifier;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class HttpFailureClassifier implements FailureClassifierInterface
{
    public function __construct(private FailureClassifierInterface $innerClassifier = new DefaultFailureClassifier) {}

    public function classify(Throwable $failure, OperationContext $context): FailureDecision
    {
        if ($failure instanceof RetryableResponseException) {
            return self::isTransientStatus($failure->response()->getStatusCode())
                ? FailureDecision::retryable()
                : FailureDecision::terminal();
        }

        if ($failure instanceof NetworkExceptionInterface) {
            return FailureDecision::retryable();
        }

        if ($failure instanceof RequestExceptionInterface) {
            return FailureDecision::terminal();
        }

        return $this->innerClassifier->classify($failure, $context);
    }

    private static function isTransientStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || ($status >= 500 && $status <= 599);
    }
}
