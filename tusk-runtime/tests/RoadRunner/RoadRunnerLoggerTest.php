<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use RoadRunner\AppLogger\DTO\V1\LogEntry;
use RoadRunner\Logger\Logger;
use Spiral\Goridge\RPC\CodecInterface;
use Spiral\Goridge\RPC\RPCInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerLogger;

final class RoadRunnerLoggerTest extends TestCase
{
    public function test_it_sorts_context_keys_before_forwarding_structured_logs(): void
    {
        $rpc = new LoggerRecordingRpc;
        $logger = new RoadRunnerLogger(new Logger($rpc));

        $logger->info('user loaded', ['z' => 'last', 'a' => 'first']);

        self::assertInstanceOf(LogEntry::class, $rpc->payload);
        $attributes = $rpc->payload->getLogAttrs();
        self::assertSame('a', $attributes[0]->getKey());
        self::assertSame('z', $attributes[1]->getKey());
    }
}

final class LoggerRecordingRpc implements RPCInterface
{
    public mixed $payload = null;

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
        $this->payload = $payload;

        return null;
    }
}
