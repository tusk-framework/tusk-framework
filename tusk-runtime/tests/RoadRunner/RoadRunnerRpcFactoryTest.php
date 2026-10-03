<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\Goridge\RPC\CodecInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Spiral\RoadRunner\Environment;
use Tusk\Runtime\RoadRunner\RoadRunnerRpcFactory;

final class RoadRunnerRpcFactoryTest extends TestCase
{
    public function test_it_creates_one_rpc_instance_for_the_worker_lifetime(): void
    {
        $rpc = new RecordingRpc;
        $calls = 0;
        $factory = new RoadRunnerRpcFactory(
            new Environment(['RR_RPC' => 'tcp://127.0.0.1:6010']),
            static function (string $address) use (&$calls, $rpc): RPCInterface {
                self::assertSame('tcp://127.0.0.1:6010', $address);
                $calls++;

                return $rpc;
            },
        );

        self::assertSame($rpc, $factory->create());
        self::assertSame($rpc, $factory->create());
        self::assertSame(1, $calls);
    }
}

final class RecordingRpc implements RPCInterface
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
