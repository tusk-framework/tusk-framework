<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Adapters\NativeLoopAdapter;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\RuntimeAdapterFactory;

final class RuntimeAdapterFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TUSK_RUNTIME');
        unset($_ENV['TUSK_RUNTIME'], $_SERVER['TUSK_RUNTIME']);
    }

    public function test_roadrunner_is_the_default_runtime(): void
    {
        $adapter = RuntimeAdapterFactory::create();

        self::assertInstanceOf(RoadRunnerAdapter::class, $adapter);
    }

    public function test_native_runtime_is_available_as_an_explicit_compatibility_mode(): void
    {
        $adapter = RuntimeAdapterFactory::create('native');

        self::assertInstanceOf(NativeLoopAdapter::class, $adapter);
    }

    public function test_environment_can_select_the_runtime(): void
    {
        putenv('TUSK_RUNTIME=native');

        self::assertInstanceOf(NativeLoopAdapter::class, RuntimeAdapterFactory::create());
    }

    public function test_unknown_runtime_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeAdapterFactory::create('swoole');
    }
}
