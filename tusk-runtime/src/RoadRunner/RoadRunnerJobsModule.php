<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;

final class RoadRunnerJobsModule implements RuntimeModuleInterface
{
    public function __construct(private readonly \Closure $handler) {}

    public function name(): string
    {
        return 'jobs';
    }

    public function register(ContainerInterface $container): void
    {
        $container->instance(self::class, $this);
    }

    public function start(): void
    {
        // The Engine/RoadRunner owns the consumer process and worker pool.
    }

    public function stop(): void
    {
        // No consumer or worker pool is owned by this module.
    }

    public function handle(JobTaskInterface $task): mixed
    {
        return ($this->handler)($task);
    }
}
