<?php

namespace Tusk\Contracts\Runtime;

interface WorkerInterface
{
    /**
     * Starts the worker process.
     */
    public function start(): void;

    /**
     * Stops the worker process.
     */
    public function stop(): void;

    /**
     * Returns the worker ID/PID.
     */
    public function getId(): int;
}
