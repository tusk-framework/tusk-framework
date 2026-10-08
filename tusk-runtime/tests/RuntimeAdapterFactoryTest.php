<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\RuntimeAdapterFactory;

final class RuntimeAdapterFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TUSK_RUNTIME');
        putenv('RR_MODE');
        unset($_ENV['TUSK_RUNTIME'], $_SERVER['TUSK_RUNTIME'], $_ENV['RR_MODE'], $_SERVER['RR_MODE']);
    }

    public function test_roadrunner_is_the_default_runtime(): void
    {
        $adapter = RuntimeAdapterFactory::create();

        self::assertInstanceOf(RoadRunnerAdapter::class, $adapter);
        self::assertSame('http', $adapter->mode());
    }

    public function test_roadrunner_alias_is_the_only_explicit_runtime_selection(): void
    {
        $adapter = RuntimeAdapterFactory::create('rr');

        self::assertInstanceOf(RoadRunnerAdapter::class, $adapter);
    }

    public function test_native_runtime_is_rejected_with_an_actionable_message(): void
    {
        putenv('TUSK_RUNTIME=native');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported runtimes: roadrunner');

        RuntimeAdapterFactory::create();
    }

    public function test_swoole_runtime_is_rejected_with_an_actionable_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported runtimes: roadrunner');

        RuntimeAdapterFactory::create('swoole');
    }

    public function test_unknown_runtime_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported runtimes: roadrunner');

        RuntimeAdapterFactory::create('unknown');
    }
}
