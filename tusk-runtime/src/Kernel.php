<?php

namespace Tusk\Runtime;

use Tusk\Contracts\Attributes\OnShutdown;
use Tusk\Contracts\Attributes\OnStart;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Web\HttpKernel;

class Kernel implements ApplicationInterface
{
    private bool $running = false;

    public function __construct(
        private ContainerInterface $container,
        private RuntimeAdapterInterface $adapter
    ) {}

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // Run OnStart hooks (Global / Master Level)
        $this->container->runHooks(OnStart::class);

        // Delegate execution to the chosen Runtime Adapter (Native, RR, Swoole)
        // The adapter receives the HttpKernel as the request handler
        $httpKernel = $this->container->get(HttpKernel::class);
        $this->adapter->start($this->container, [$httpKernel, 'handle']);

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
