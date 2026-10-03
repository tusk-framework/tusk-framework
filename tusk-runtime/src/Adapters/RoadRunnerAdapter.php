<?php

namespace Tusk\Runtime\Adapters;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Modules\RoadRunnerHttpModule;

class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    public function __construct(private readonly RoadRunnerHttpModule $http = new RoadRunnerHttpModule()) {}

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $this->http->start($container, $requestHandler);
    }

    public function stop(): void
    {
        $this->http->stop();
    }

    public function getName(): string
    {
        return 'roadrunner';
    }
}
