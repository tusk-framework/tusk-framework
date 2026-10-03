<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Closure;
use Spiral\Goridge\RPC\RPC;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\EnvironmentInterface;

final class RoadRunnerRpcFactory implements RoadRunnerRpcFactoryInterface
{
    private ?RPCInterface $rpc = null;

    /**
     * @param Closure(non-empty-string): RPCInterface|null $creator
     */
    public function __construct(
        private readonly ?EnvironmentInterface $environment = null,
        private readonly ?Closure $creator = null,
    ) {}

    public function create(): RPCInterface
    {
        if ($this->rpc !== null) {
            return $this->rpc;
        }

        $environment = $this->environment ?? Environment::fromGlobals();
        $address = $environment->getRPCAddress();
        $creator = $this->creator ?? static fn (string $connection): RPCInterface => RPC::create($connection);

        return $this->rpc = $creator($address);
    }
}
