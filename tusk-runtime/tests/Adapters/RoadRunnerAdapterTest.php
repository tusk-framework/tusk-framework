<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobProcessor;
use Tusk\Runtime\Jobs\JobRetryConfiguration;
use Tusk\Runtime\RoadRunner\RoadRunnerJobsModule;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\WorkerInterface;

final class RoadRunnerAdapterTest extends TestCase
{
    public function test_it_identifies_as_roadrunner_and_can_be_stopped_before_start(): void
    {
        $adapter = new RoadRunnerAdapter();

        $adapter->stop();

        self::assertSame('roadrunner', $adapter->getName());
    }

    public function test_jobs_loop_processes_each_task_and_exits_on_nullable_shutdown(): void
    {
        $task = $this->createMock(ReceivedTaskInterface::class);
        $consumer = new class($task) implements ConsumerInterface {
            private int $calls = 0;
            public function __construct(private ReceivedTaskInterface $task) {}
            public function waitTask(): ?ReceivedTaskInterface { return ++$this->calls === 1 ? $this->task : null; }
        };
        $processor = new JobProcessor(
            $this->createMock(ContainerInterface::class),
            new JobHandlerRegistry([]),
            $this->createMock(\Tusk\Contracts\Runtime\LifecycleManagerInterface::class),
            JobRetryConfiguration::fromArray([]),
        );
        $module = new RoadRunnerJobsModule($processor);
        $container = new class($module) implements ContainerInterface {
            public function __construct(private RoadRunnerJobsModule $module) {}
            public function instance(string $id, object $instance): void {}
            public function get(string $id): object { return $this->module; }
            public function has(string $id): bool { return true; }
            public function runHooks(string $attributeClass): void {}
            public function runLifecycleHooks(string $event): void {}
            public function resetScope(string $scope): void {}
        };
        $worker = $this->createMock(WorkerInterface::class);
        $worker->expects(self::never())->method('stop');
        $adapter = new RoadRunnerAdapter(
            mode: 'jobs',
            consumerFactory: static fn (WorkerInterface $worker) => $consumer,
            workerFactory: static fn () => $worker,
        );
        // A malformed task would fail during processor dispatch; reaching its task metadata proves delivery.
        $task->method('getPayload')->willReturn('not-json');
        $task->method('getId')->willReturn('id');
        $task->method('getPipeline')->willReturn('pipe');
        $task->method('getName')->willReturn('unknown');
        $task->method('getHeaders')->willReturn([]);
        $task->expects(self::once())->method('nack');

        $adapter->start($container, static function (): never { throw new \LogicException('HTTP handler invoked.'); });

        self::assertSame('jobs', $adapter->mode());
        self::assertSame('roadrunner', $adapter->getName());
    }

    public function test_stop_requests_roadrunner_worker_stop_while_jobs_loop_is_waiting(): void
    {
        $worker = $this->createMock(WorkerInterface::class);
        $worker->expects(self::once())->method('stop');
        $adapter = null;
        $consumer = new class($adapter) implements ConsumerInterface {
            public function __construct(private ?RoadRunnerAdapter &$adapter) {}
            public function waitTask(): ?ReceivedTaskInterface
            {
                $this->adapter?->stop();
                return null;
            }
        };
        $adapter = new RoadRunnerAdapter(
            mode: 'jobs',
            consumerFactory: static fn (WorkerInterface $worker) => $consumer,
            workerFactory: static fn () => $worker,
        );

        $adapter->start($this->createMock(ContainerInterface::class), static function (): void {});
    }
}
