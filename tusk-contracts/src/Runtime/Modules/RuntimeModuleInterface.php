<?php

namespace Tusk\Contracts\Runtime\Modules;

use Tusk\Contracts\Container\ContainerInterface;

interface RuntimeModuleInterface
{
    public function name(): string;

    public function register(ContainerInterface $container): void;

    public function start(): void;

    public function stop(): void;
}
