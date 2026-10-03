<?php

namespace Tusk\Runtime;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Web\HttpKernel;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;

final class Kernel implements ApplicationInterface
{
    private bool $running = false;

    private readonly LifecycleManagerInterface $lifecycle;

    private readonly RuntimeModuleRegistry $modules;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RuntimeAdapterInterface $adapter,
        ?LifecycleManagerInterface $lifecycle = null,
        ?RuntimeModuleRegistry $modules = null,
    ) {
        $this->lifecycle = $lifecycle ?? new LifecycleManager($container);
        $this->modules = $modules ?? new RuntimeModuleRegistry();
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        try {
            $this->lifecycle->applicationStart();
            $this->lifecycle->workerStart();
            $this->modules->start();

            $httpKernel = $this->container->get(HttpKernel::class);
            $this->adapter->start($this->container, $this->lifecycle->wrap([$httpKernel, 'handle']));
        } finally {
            try {
                $this->modules->stop();
            } finally {
                try {
                    $this->lifecycle->workerStop();
                } finally {
                    try {
                        $this->lifecycle->applicationStop();
                    } finally {
                        $this->running = false;
                    }
                }
            }
        }
    }

    public function shutdown(): void
    {
        if (! $this->running) {
            return;
        }

        try {
            $this->modules->stop();
        } finally {
            try {
                $this->lifecycle->workerStop();
            } finally {
                $this->lifecycle->applicationStop();
                $this->running = false;
            }
        }
    }

    public function stop(): void
    {
        $this->adapter->stop();
    }
}
