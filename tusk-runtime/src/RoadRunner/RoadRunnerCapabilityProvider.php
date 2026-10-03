<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use RoadRunner\Lock\Lock;
use RoadRunner\Logger\Logger;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Jobs\Jobs;
use Spiral\RoadRunner\KeyValue\Factory;
use Spiral\RoadRunner\Metrics\Metrics;
use Tusk\Contracts\Runtime\Capabilities\CapabilityProviderInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityUnavailableException;

final class RoadRunnerCapabilityProvider implements CapabilityProviderInterface
{
    private ?RPCInterface $rpc = null;

    /** @var array<string, object> */
    private array $capabilities = [];

    public function __construct(
        private readonly RoadRunnerRpcFactoryInterface $rpcFactory,
        private readonly string $kvStore = 'default',
    ) {}

    public function supports(string $name): bool
    {
        return in_array($name, ['jobs', 'kv', 'lock', 'metrics', 'logger'], true);
    }

    public function provide(string $name): object
    {
        if (isset($this->capabilities[$name])) {
            return $this->capabilities[$name];
        }

        if (! $this->supports($name)) {
            throw new CapabilityUnavailableException($name);
        }

        $rpc = $this->rpc();

        return $this->capabilities[$name] = match ($name) {
            'jobs' => new RoadRunnerJobs(new Jobs($rpc)),
            'kv' => new RoadRunnerKv((new Factory($rpc))->select($this->kvStore)),
            'lock' => new RoadRunnerLock(new Lock($rpc)),
            'metrics' => new RoadRunnerMetrics(new Metrics($rpc)),
            'logger' => new RoadRunnerLogger(new Logger($rpc)),
            default => throw new CapabilityUnavailableException($name),
        };
    }

    private function rpc(): RPCInterface
    {
        return $this->rpc ??= $this->rpcFactory->create();
    }
}
