<?php

namespace Tusk\Runtime\Tests\Modules;

use PHPUnit\Framework\TestCase;
use Spiral\Goridge\RPC\RPCInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueInterface;
use Tusk\Runtime\Modules\RoadRunnerCapabilitiesModule;
use Tusk\Runtime\RoadRunner\RoadRunnerRpcFactoryInterface;

final class RoadRunnerCapabilitiesModuleTest extends TestCase
{
    public function test_jobs_capability_is_registered_before_application_start(): void
    {
        $container = new class implements ContainerInterface
        {
            /** @var array<string, object> */
            private array $instances = [];

            public function instance(string $id, object $instance): void
            {
                $this->instances[$id] = $instance;
            }

            public function get(string $id): object
            {
                return $this->instances[$id] ?? throw new \RuntimeException('Service not found: '.$id);
            }

            public function has(string $id): bool
            {
                return isset($this->instances[$id]);
            }

            public function runHooks(string $attributeClass): void {}

            public function runLifecycleHooks(string $event): void {}

            public function resetScope(string $scope): void {}
        };
        $rpcFactory = $this->createMock(RoadRunnerRpcFactoryInterface::class);
        $rpcFactory->expects(self::once())->method('create')->willReturn($this->createStub(RPCInterface::class));
        $module = new RoadRunnerCapabilitiesModule(['jobs'], $rpcFactory);

        $module->register($container);

        // Kernel runs applicationStart immediately after module registration and before start().
        self::assertInstanceOf(QueueInterface::class, $container->get(QueueInterface::class));
    }
}
