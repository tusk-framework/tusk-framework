<?php

declare(strict_types=1);

namespace Tusk\Runtime\Modules;

use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;

final class RoadRunnerGrpcModule implements RuntimeModuleInterface
{
    private ?Server $server = null;

    private bool $started = false;

    /** @var array<class-string<ServiceInterface>, ServiceInterface> */
    private array $services = [];

    public function name(): string
    {
        return 'roadrunner.grpc';
    }

    /**
     * @param  class-string<ServiceInterface>  $interface
     */
    public function registerService(string $interface, ServiceInterface $service): void
    {
        $this->services[$interface] = $service;
    }

    public function serviceCount(): int
    {
        return count($this->services);
    }

    public function register(ContainerInterface $container): void
    {
        $container->instance(self::class, $this);
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->server = new Server;

        foreach ($this->services as $interface => $service) {
            $this->server->registerService($interface, $service);
        }

        $this->started = true;
    }

    public function stop(): void
    {
        $this->server = null;
        $this->started = false;
    }
}
