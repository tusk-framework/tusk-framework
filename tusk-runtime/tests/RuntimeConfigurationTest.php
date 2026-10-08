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
        self::assertSame(3, $configuration->jobRetry()->maxAttempts());
        self::assertSame(1, $configuration->jobRetry()->delaySeconds());
        self::assertSame('http', $configuration->executionMode());
    }

    public function test_execution_mode_is_normalized_and_independent_from_runtime_adapter(): void
    {
        $configuration = RuntimeConfiguration::fromArray(['runtime' => ['mode' => '  JoBs  ', 'adapter' => 'rr']]);

        self::assertSame('jobs', $configuration->executionMode());
        self::assertSame('roadrunner', $configuration->adapter());
    }

    public function test_rr_mode_environment_selects_jobs_at_configuration_boundary(): void
    {
        putenv('RR_MODE= JoBs ');
        try {
            self::assertSame('jobs', RuntimeConfiguration::fromArray([])->executionMode());
        } finally {
            putenv('RR_MODE');
            unset($_ENV['RR_MODE'], $_SERVER['RR_MODE']);
        }
    }

    public function test_unknown_execution_mode_is_rejected_actionably(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported RoadRunner modes: http, jobs.');

        RuntimeConfiguration::fromArray(['runtime' => ['mode' => 'grpc']]);
    }

    public function test_job_retry_reads_nested_runtime_configuration(): void
    {
        $configuration = RuntimeConfiguration::fromArray(['runtime' => ['jobs' => ['retry' => ['max_attempts' => 4, 'delay_seconds' => 0]]]]);
        self::assertSame(4, $configuration->jobRetry()->maxAttempts());
        self::assertSame(0, $configuration->jobRetry()->delaySeconds());
    }

    public function test_unknown_modules_are_rejected_with_the_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capabilities.unknown');

        RuntimeConfiguration::fromArray([
            'runtime' => ['modules' => ['capabilities.unknown']],
        ]);
    }

    public function test_native_runtime_configuration_is_rejected_with_an_actionable_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported runtime adapter "native". Supported adapters: roadrunner.');

        RuntimeConfiguration::fromArray([
            'runtime' => [
                'adapter' => 'native',
            ],
        ]);
    }

    public function test_swoole_runtime_configuration_is_rejected_with_an_actionable_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported runtime adapter "swoole". Supported adapters: roadrunner.');

        RuntimeConfiguration::fromArray([
            'runtime' => ['adapter' => 'swoole'],
        ]);
    }
}
