<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
class SwooleAdapter implements RuntimeAdapterInterface
{
    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        throw new \LogicException('SwooleAdapter is not implemented yet. Configure a supported runtime adapter instead.');
    }

    public function stop(): void
    {
        // No server is started until a concrete implementation exists.
    }

    public function getName(): string
    {
        return 'swoole';
    }
}
