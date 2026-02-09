<?php

namespace Tusk\Runtime\Process;

class LoopProcessManager implements ProcessManagerInterface
{
    private array $workers = [];

    public function spawn(callable $workerLogic): int
    {
        // On Windows/Non-PCNTL, we can't truly fork. 
        // For v0.2.0 verification, we might just run the logic directly (blocking)
        // OR warn that concurrency is not supported.

        // Simulating a "worker" by running it briefly? 
        // No, that blocks the supervisor.

        echo "[WARNING] Tusk is running in Non-Concurrent Mode (Windows/No PCNTL).\n";
        echo "[WARNING] Worker logic will execute sequentially in the main process.\n";

        $workerLogic();

        // Return a fake PID
        return rand(1000, 9999);
    }

    public function wait(): ?int
    {
        // Since we run blocking, there's nothing to wait for.
        return null; // No exited children
    }

    public function signal(int $pid, int $signal): void
    {
        // No-op
    }

    public function supportsConcurrency(): bool
    {
        return false;
    }
}
