<?php

namespace Tusk\Contracts\Core;

interface ApplicationInterface
{
    // Legacy lifecycle contract. HTTP handling and worker bootstrapping are
    // exposed by the concrete Foundation application without changing Kernel.
    /**
     * Starts the application and its persistent runtime.
     */
    public function start(): void;

    /**
     * Gracefully shuts down the application.
     */
    public function shutdown(): void;
}
