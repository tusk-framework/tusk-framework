<?php

declare(strict_types=1);

namespace Tusk\Runtime\Adapters;

use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Modules\RoadRunnerHttpModule;

final class RoadRunnerAdapter implements RuntimeAdapterInterface
{
    private readonly RoadRunnerHttpModule $http;

    /**
     * The worker channel is injectable for deterministic tests; production uses
     * the RoadRunner HTTP module and its real worker channel.
     */
    public function __construct(RoadRunnerHttpModule|PSR7WorkerInterface|null $runtime = null)
    {
        $this->http = $runtime instanceof RoadRunnerHttpModule
            ? $runtime
            : new RoadRunnerHttpModule(null, $runtime);
    }

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
