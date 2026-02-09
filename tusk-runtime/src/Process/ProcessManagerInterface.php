<?php

namespace Tusk\Runtime\Process;

interface ProcessManagerInterface
{
    /**
     * Spawns a new worker process.
     * 
     * @param callable $workerLogic The logic to execute in the worker.
     * @return int The Process ID (PID) of the spawned worker.
     */
    public function spawn(callable $workerLogic): int;

    /**
     * Waits for any child process to exit.
     * 
     * @return int|null The PID of the exited process, or null if no process exited (non-blocking check).
     */
    public function wait(): ?int;

    /**
     * Signals a process to stop.
     * 
     * @param int $pid
     * @param int $signal
     */
    public function signal(int $pid, int $signal): void;

    /**
     * Returns true if the current environment supports forking/parallelism.
     */
    public function supportsConcurrency(): bool;
}
