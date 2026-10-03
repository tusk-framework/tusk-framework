<?php

namespace Tusk\Runtime\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Runtime\RuntimeConfiguration;

final class RuntimeConfigurationTest extends TestCase
{
    public function test_it_normalizes_the_documented_runtime_configuration(): void
    {
        $configuration = RuntimeConfiguration::fromArray([
            'runtime' => [
                'adapter' => 'rr',
                'modules' => ['http', 'capabilities.jobs', 'capabilities.kv', 'grpc'],
            ],
        ]);

        self::assertSame('roadrunner', $configuration->adapter());
        self::assertSame(['http', 'capabilities.jobs', 'capabilities.kv', 'grpc'], $configuration->modules());
    }

    public function test_default_configuration_remains_roadrunner_http(): void
    {
        $configuration = RuntimeConfiguration::fromArray([]);

        self::assertSame('roadrunner', $configuration->adapter());
        self::assertSame(['http'], $configuration->modules());
    }

    public function test_unknown_modules_are_rejected_with_the_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capabilities.unknown');

        RuntimeConfiguration::fromArray([
            'runtime' => ['modules' => ['capabilities.unknown']],
        ]);
    }

    public function test_native_runtime_rejects_roadrunner_only_modules(): void
    {
        $this->expectExceptionMessage('requires the RoadRunner adapter');

        RuntimeConfiguration::fromArray([
            'runtime' => [
                'adapter' => 'native',
                'modules' => ['http', 'capabilities.kv'],
            ],
        ]);
    }
}
