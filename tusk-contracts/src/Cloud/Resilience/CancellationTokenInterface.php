<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

interface CancellationTokenInterface
{
    public function isCancellationRequested(): bool;
}
