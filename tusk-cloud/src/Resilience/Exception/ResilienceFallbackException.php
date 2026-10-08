<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Exception;

use RuntimeException;
use Throwable;

final class ResilienceFallbackException extends RuntimeException
{
    public function __construct(
        string $operation,
        Throwable $originalFailure,
        private readonly Throwable $fallbackFailure,
    ) {
        parent::__construct("Fallback for operation '{$operation}' failed.", previous: $originalFailure);
    }

    public function fallbackFailure(): Throwable
    {
        return $this->fallbackFailure;
    }
}
