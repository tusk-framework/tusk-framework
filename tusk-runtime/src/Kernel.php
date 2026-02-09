<?php

namespace Tusk\Runtime;

use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Attributes\OnStart;
use Tusk\Contracts\Attributes\OnShutdown;
use Tusk\Runtime\Supervisor\Supervisor;

class Kernel implements ApplicationInterface
{
    private bool $running = false;
    private Supervisor $supervisor;

    public function __construct(
        private ContainerInterface $container
    ) {
        $this->supervisor = new Supervisor(workerCount: 1); // Default to 1 for now
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // Run OnStart hooks (Global / Master Level)
        $this->container->runHooks(OnStart::class);

        // Define the logic that runs inside each worker
        $workerLogic = function () {
            // New process started (or simulated)
            // Reset worker-scoped services to ensure fresh instances
            $this->container->resetScope('worker');

            // In a real worker, this loop would accept requests
            // For v0.2, we just simulate work
            echo "[Worker] Started. Ready for work.\n";

            while (true) {
                // Simulate processing
                usleep(500000);
            }
        };

        // Delegate to Supervisor
        $this->supervisor->start($workerLogic);

        $this->shutdown();
    }

    public function shutdown(): void
    {
        if (!$this->running && !empty($this->container)) {
            // ensure hooks run even if start wasn't fully successful
        }

        $this->running = false;

        // Run OnShutdown hooks
        $this->container->runHooks(OnShutdown::class);
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
