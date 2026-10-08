<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Exception;

use RuntimeException;

final class OperationCancelledException extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct("Operation '{$operation}' was cancelled.");
    }
}
