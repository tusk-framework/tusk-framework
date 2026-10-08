<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Event;

enum OperationRejectionReason: string
{
    case CIRCUIT_OPEN = 'circuit_open';
    case BULKHEAD_REJECTED = 'bulkhead_rejected';
    case BULKHEAD_TIMEOUT = 'bulkhead_timeout';
    case RATE_LIMIT_REJECTED = 'rate_limit_rejected';
    case DEADLINE_EXCEEDED = 'deadline_exceeded';
    case CANCELLED = 'cancelled';
}
