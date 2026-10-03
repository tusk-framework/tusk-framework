<?php

declare(strict_types=1);

namespace Tusk\Runtime\Modules;

use LogicException;
use Psr\Log\LoggerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityProviderInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityRegistryInterface;
use Tusk\Contracts\Runtime\Capabilities\KeyValueStoreInterface;
use Tusk\Contracts\Runtime\Capabilities\LockInterface;
use Tusk\Contracts\Runtime\Capabilities\MetricsInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Runtime\Capabilities\CapabilityRegistry;
use Tusk\Runtime\RoadRunner\RoadRunnerCapabilityProvider;
use Tusk\Runtime\RoadRunner\RoadRunnerRpcFactory;
use Tusk\Runtime\RoadRunner\RoadRunnerRpcFactoryInterface;

final class RoadRunnerCapabilitiesModule implements RuntimeModuleInterface
{
    private ?ContainerInterface $container = null;

    private ?CapabilityRegistryInterface $registry = null;

    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly ?RoadRunnerRpcFactoryInterface $rpcFactory = null,
    ) {}

    public function name(): string
    {
        return 'roadrunner.capabilities';
    }

    public function register(ContainerInterface $container): void
    {
        $provider = new RoadRunnerCapabilityProvider($this->rpcFactory ?? new RoadRunnerRpcFactory);
        $registry = new CapabilityRegistry([$provider]);

        foreach ($this->capabilities as $capability) {
            if (! $provider->supports($capability)) {
                throw new LogicException(sprintf('Unsupported RoadRunner capability "%s".', $capability));
            }
        }

        $this->container = $container;
        $this->registry = $registry;
        $container->instance(CapabilityProviderInterface::class, $provider);
        $container->instance(CapabilityRegistryInterface::class, $registry);
    }

    public function start(): void
    {
        if ($this->container === null || $this->registry === null) {
            throw new LogicException('RoadRunner capabilities must be registered before they start.');
        }

        foreach ($this->capabilities as $capability) {
            $this->container->instance($this->contractFor($capability), $this->registry->get($capability));
        }
    }

    public function stop(): void {}

    private function contractFor(string $capability): string
    {
        return match ($capability) {
            'jobs' => QueueInterface::class,
            'kv' => KeyValueStoreInterface::class,
            'lock' => LockInterface::class,
            'metrics' => MetricsInterface::class,
            'logger' => LoggerInterface::class,
            default => throw new LogicException(sprintf('Unsupported RoadRunner capability "%s".', $capability)),
        };
    }
}
