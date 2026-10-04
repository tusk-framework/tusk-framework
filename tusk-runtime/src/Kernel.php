<?php

declare(strict_types=1);

namespace Tusk\Runtime;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Core\ApplicationInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Web\HttpKernel;

final class Kernel implements ApplicationInterface
{
    private bool $running = false;

    private bool $inLifecycle = false;

    private bool $stopping = false;

    private readonly LifecycleManagerInterface $lifecycle;

    private readonly RuntimeModuleRegistry $modules;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RuntimeAdapterInterface $adapter,
        ?LifecycleManagerInterface $lifecycle = null,
        ?RuntimeModuleRegistry $modules = null,
        ?RuntimeObservability $observability = null,
    ) {
        $this->modules = $modules ?? new RuntimeModuleRegistry;
        if ($observability === null && $container->has(RuntimeObservability::class)) {
            $candidate = $container->get(RuntimeObservability::class);
            $observability = $candidate instanceof RuntimeObservability ? $candidate : null;
        }
        $observability?->setRuntime($adapter->getName());
        $this->lifecycle = $lifecycle ?? new LifecycleManager($container, null, $observability);
    }

    public function start(): void
    {
        $this->run();
    }

    public function run(?callable $requestHandler = null): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->stopping = false;
        $this->inLifecycle = true;

        try {
            $this->lifecycle->applicationStart();
            $this->lifecycle->workerStart();
            $this->modules->start();
            $requestHandler ??= [$this->container->get(HttpKernel::class), 'handle'];
            $this->adapter->start($this->container, $this->lifecycle->wrap($requestHandler));
        } finally {
            $this->inLifecycle = false;
            $this->finishShutdown();
        }
    }

    public function shutdown(): void
    {
        if (! $this->running) {
            return;
        }

        if ($this->inLifecycle) {
            $this->stop();

            return;
        }

        $this->finishShutdown();
    }

    public function stop(): void
    {
        if (! $this->running || $this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->adapter->stop();
    }

    private function finishShutdown(): void
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
                try {
                    $this->lifecycle->applicationStop();
                } finally {
                    $this->running = false;
                    $this->stopping = false;
                }
            }
        }
    }
}
