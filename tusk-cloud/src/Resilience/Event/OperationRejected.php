<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Event;

final readonly class OperationRejected
{
    public function __construct(
        public string $operation,
        public OperationRejectionReason $reason,
    ) {}
}
