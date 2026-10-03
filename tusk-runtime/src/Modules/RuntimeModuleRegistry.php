<?php

declare(strict_types=1);

namespace Tusk\Runtime\Modules;

use Throwable;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;

final class RuntimeModuleRegistry
{
    /** @var list<RuntimeModuleInterface> */
    private array $modules;

    /**
     * @param  iterable<RuntimeModuleInterface>  $modules
     */
    public function __construct(iterable $modules = [])
    {
        $this->modules = [];

        foreach ($modules as $module) {
            $this->modules[] = $module;
        }
    }

    public function register(ContainerInterface $container): void
    {
        foreach ($this->modules as $module) {
            $module->register($container);
        }
    }

    public function start(): void
    {
        foreach ($this->modules as $module) {
            $module->start();
        }
    }

    public function stop(): void
    {
        $firstFailure = null;

        foreach (array_reverse($this->modules) as $module) {
            try {
                $module->stop();
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }
}
