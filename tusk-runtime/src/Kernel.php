<?php

namespace Tusk\Runtime;

use Tusk\Contracts\Attributes\OnShutdown;
use Tusk\Contracts\Attributes\OnStart;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Runtime\Supervisor\Supervisor;

class Kernel implements ApplicationInterface
{
    private bool $running = false;

    public function __construct(
        private ContainerInterface $container,
        private \Tusk\Contracts\Runtime\RuntimeAdapterInterface $adapter
    ) {}

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // Run OnStart hooks (Global / Master Level)
        $this->container->runHooks(OnStart::class);

        // Define the request handler logic
        $requestHandler = function ($request) {
            // Here, we would map the raw request to a PSR-7 Request,
            // dispatch it through Middlewares/Router, and return a Response.
            // For now, it's just a dummy simulation:
            return "Response for request";
        };

        // Delegate execution to the chosen Runtime Adapter (Native, RR, Swoole)
        $this->adapter->start($this->container, $requestHandler);

        // Once the adapter stops, we run shutdown procedures
        $this->shutdown();
    }

    public function shutdown(): void
    {
        if (! $this->running) {
            return;
        }

        $this->running = false;

        // Run OnShutdown hooks
        $this->container->runHooks(OnShutdown::class);
    }

    public function stop(): void
    {
        $this->adapter->stop();
        $this->running = false;
    }
}
