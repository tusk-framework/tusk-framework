<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\Goridge\RPC\CodecInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityUnavailableException;
use Tusk\Runtime\RoadRunner\RoadRunnerCapabilityProvider;
use Tusk\Runtime\RoadRunner\RoadRunnerRpcFactoryInterface;

final class RoadRunnerCapabilityProviderTest extends TestCase
{
    public function test_it_reuses_one_rpc_for_all_capabilities_and_caches_each_adapter(): void
    {
        $rpc = new ProviderRecordingRpc();
        $factory = new RecordingRpcFactory($rpc);
        $provider = new RoadRunnerCapabilityProvider($factory);

        self::assertTrue($provider->supports('kv'));
        self::assertTrue($provider->supports('metrics'));
        self::assertTrue($provider->supports('logger'));
        self::assertTrue($provider->supports('jobs'));

        self::assertSame($provider->provide('kv'), $provider->provide('kv'));
        $provider->provide('metrics');
        $provider->provide('logger');

        self::assertSame(1, $factory->createCalls);
    }

    public function test_it_rejects_an_unknown_capability(): void
    {
        $provider = new RoadRunnerCapabilityProvider(new RecordingRpcFactory(new ProviderRecordingRpc()));

        $this->expectException(CapabilityUnavailableException::class);
        $provider->provide('unknown');
    }
}

final class RecordingRpcFactory implements RoadRunnerRpcFactoryInterface
{
    public int $createCalls = 0;

    public function __construct(private readonly RPCInterface $rpc) {}

    public function create(): RPCInterface
    {
        ++$this->createCalls;

        return $this->rpc;
    }
}

final class ProviderRecordingRpc implements RPCInterface
{
    public function withServicePrefix(string $service): self
    {
        return $this;
    }

    public function withCodec(CodecInterface $codec): self
    {
        return $this;
    }

    public function call(string $method, mixed $payload, mixed $options = null): mixed
    {
        return null;
    }
}
