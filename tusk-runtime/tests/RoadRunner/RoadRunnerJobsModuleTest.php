<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Runtime\Capabilities\JobTaskInterface;
use Tusk\Contracts\Runtime\Capabilities\QueueMessageInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobProcessor;
use Tusk\Runtime\Jobs\JobRetryConfiguration;
use Tusk\Runtime\RoadRunner\RoadRunnerJobsModule;

final class RoadRunnerJobsModuleTest extends TestCase
{
    public function test_it_registers_a_handler_boundary_without_starting_a_consumer(): void
    {
        $task = $this->createMock(JobTaskInterface::class);
        $message = $this->createMock(QueueMessageInterface::class);
        $message->method('id')->willReturn('job-1');
        $message->method('queue')->willReturn('emails');
        $message->method('name')->willReturn('unknown');
        $message->method('payload')->willReturn('{}');
        $message->method('headers')->willReturn([]);
        $task->method('message')->willReturn($message);
        $task->method('attempt')->willReturn(1);
        $task->expects(self::once())->method('fail');
        $lifecycle = $this->createMock(LifecycleManagerInterface::class);
        $lifecycle->expects(self::once())->method('jobStart');
        $lifecycle->expects(self::once())->method('jobEnd');
        $container = new Container;
        $module = new RoadRunnerJobsModule(new JobProcessor($container, new JobHandlerRegistry([]), $lifecycle, JobRetryConfiguration::fromArray([])));

        $module->register($container);
        $module->start();

        self::assertSame('jobs', $module->name());
        self::assertNull($module->handle($task));
        self::assertSame($module, $container->get(RoadRunnerJobsModule::class));

        $module->stop();
    }
}
