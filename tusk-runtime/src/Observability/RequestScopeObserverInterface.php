<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

interface RequestScopeObserverInterface
{
    public function requestScopeReset(bool $anomaly = false): void;
}
