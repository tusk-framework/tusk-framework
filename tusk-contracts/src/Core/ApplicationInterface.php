<?php

namespace Tusk\Contracts\Core;

interface ApplicationInterface
{
    /**
     * Starts the application and its persistent runtime.
     */
    public function start(): void;

    /**
     * Gracefully shuts down the application.
     */
    public function shutdown(): void;
}
