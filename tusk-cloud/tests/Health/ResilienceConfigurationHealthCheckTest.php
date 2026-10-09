<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Health;

use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Health\HealthCheckInterface;
use Tusk\Cloud\Health\ResilienceConfigurationHealthCheck;

final class ResilienceConfigurationHealthCheckTest extends TestCase
{
    public function test_it_reports_validated_local_configuration_as_healthy(): void
    {
        $check = new ResilienceConfigurationHealthCheck;

        self::assertSame('resilience_configuration', $check->getName());
        self::assertTrue($check->check());
        self::assertInstanceOf(HealthCheckInterface::class, $check);
    }

    public function test_it_has_no_remote_dependency(): void
    {
        $constructor = (new \ReflectionClass(ResilienceConfigurationHealthCheck::class))->getConstructor();

        self::assertNull($constructor);
    }
}
