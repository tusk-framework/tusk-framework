<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Exception;

use RuntimeException;
use Throwable;

final class ResilienceDeadlineExceededException extends RuntimeException
{
    public function __construct(private readonly string $operation, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Deadline exceeded for operation "%s".', $operation), 0, $previous);
    }

    public function operation(): string
    {
        return $this->operation;
    }
}
