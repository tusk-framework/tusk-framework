<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Event;

use InvalidArgumentException;
use Tusk\Cloud\Resilience\State;

final readonly class CircuitStateChanged
{
    public function __construct(
        public string $operation,
        public State $previous,
        public State $current,
    ) {
        if ($previous === $current) {
            throw new InvalidArgumentException('Previous and current states must differ.');
        }
    }
}
