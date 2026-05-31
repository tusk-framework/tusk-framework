<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        throw new \LogicException('RoadRunnerAdapter is not implemented yet. Configure a supported runtime adapter instead.');
    }

    public function stop(): void
    {
        // No runtime loop is started until a concrete implementation exists.
    }

    public function getName(): string
    {
        return 'roadrunner';
    }
}
