<?php

declare(strict_types=1);

namespace Tusk\Contracts\Observability;

interface WorkerLifecycleCheckpointInterface
{
    public function checkpoint(string $boundary): void;
}
