<?php

namespace Tusk\Contracts\Runtime;

use Tusk\Contracts\Container\ContainerInterface;

interface RuntimeAdapterInterface
{
    /**
     * Starts the runtime loop and blocks to handle incoming requests/tasks.
     *
     * @param ContainerInterface $container      The application container
     * @param callable           $requestHandler The logic to execute per request function(mixed $request): mixed
     */
    public function start(ContainerInterface $container, callable $requestHandler): void;

    /**
     * Gracefully triggers a shutdown of the runtime loop.
     */
    public function stop(): void;

    /**
     * Returns the name of the runtime engine (e.g., 'native', 'roadrunner', 'swoole').
     */
    public function getName(): string;
}
