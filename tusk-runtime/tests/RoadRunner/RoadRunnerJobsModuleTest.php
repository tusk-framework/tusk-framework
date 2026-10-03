<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Container\Container;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerJobsModule;

final class RoadRunnerJobsModuleTest extends TestCase
{
    public function test_it_registers_a_handler_boundary_without_starting_a_consumer(): void
    {
        $task = $this->createMock(JobTaskInterface::class);
        $module = new RoadRunnerJobsModule(static fn (JobTaskInterface $received): string => 'handled');
        $container = new Container();

        $module->register($container);
        $module->start();

        self::assertSame('jobs', $module->name());
        self::assertSame('handled', $module->handle($task));
        self::assertSame($module, $container->get(RoadRunnerJobsModule::class));

        $module->stop();
    }
}
