<?php

namespace Tusk\Runtime\Tests\Modules;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Modules\RoadRunnerGrpcModule;

interface TestGrpcServiceInterface extends ServiceInterface
{
    public const NAME = 'test.Service';
}

final class TestGrpcService implements TestGrpcServiceInterface {}

final class RoadRunnerGrpcModuleTest extends TestCase
{
    public function test_it_keeps_a_unique_tusk_service_registry_and_does_not_expose_the_server(): void
    {
        $module = new RoadRunnerGrpcModule;
        $service = new TestGrpcService;
        $container = new Container;

        $module->registerService(TestGrpcServiceInterface::class, $service);
        $module->registerService(TestGrpcServiceInterface::class, $service);
        $module->register($container);

        self::assertSame('roadrunner.grpc', $module->name());
        self::assertSame(1, $module->serviceCount());
        self::assertSame($module, $container->get(RoadRunnerGrpcModule::class));

        $module->start();
        $module->start();
        $module->stop();
    }
}
