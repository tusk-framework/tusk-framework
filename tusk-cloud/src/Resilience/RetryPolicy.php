<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final readonly class RetryPolicy
{
    private function __construct(
        private int $maxAttempts,
        private BackoffStrategyInterface $backoffStrategy,
        private FailureClassifierInterface $classifier,
        private bool $allowUnsafeRetries,
    ) {}

    public static function create(
        int $maxAttempts = 1,
        ?BackoffStrategyInterface $backoffStrategy = null,
        ?FailureClassifierInterface $classifier = null,
        bool $allowUnsafeRetries = false,
    ): self {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least one.');
        }

        return new self(
            $maxAttempts,
            $backoffStrategy ?? FixedBackoff::create(),
            $classifier ?? new DefaultFailureClassifier,
            $allowUnsafeRetries,
        );
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function backoffStrategy(): BackoffStrategyInterface
    {
        return $this->backoffStrategy;
    }

    public function classifier(): FailureClassifierInterface
    {
        return $this->classifier;
    }

    public function allowUnsafeRetries(): bool
    {
        return $this->allowUnsafeRetries;
    }

    public function canRetryAfter(int $completedAttempts, OperationContext $context): bool
    {
        if ($completedAttempts < 1) {
            throw new InvalidArgumentException('Completed attempts must be at least one.');
        }

        return $completedAttempts < $this->maxAttempts
            && ($context->retryAllowed() || $this->allowUnsafeRetries);
    }
}
