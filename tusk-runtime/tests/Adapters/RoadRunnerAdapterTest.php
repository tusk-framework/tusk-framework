<?php

declare(strict_types=1);

namespace Tusk\Runtime\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\WritableHeadersInterface;
use Spiral\RoadRunner\WorkerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobProcessor;
use Tusk\Runtime\Jobs\JobRetryConfiguration;
use Tusk\Runtime\RoadRunner\RoadRunnerJobsModule;

final class RoadRunnerAdapterTest extends TestCase
{
    public function test_it_identifies_as_roadrunner_and_can_be_stopped_before_start(): void
    {
        $adapter = new RoadRunnerAdapter;

        $adapter->stop();

        self::assertSame('roadrunner', $adapter->getName());
    }

    public function test_jobs_loop_processes_each_task_and_exits_on_nullable_shutdown(): void
    {
        $task = new class implements ReceivedTaskInterface
        {
            public bool $nacked = false;

            public function getId(): string
            {
                return 'id';
            }

            public function getPipeline(): string
            {
                return 'pipe';
            }

            public function getName(): string
            {
                return 'unknown';
            }

            public function getPayload(): string
            {
                return 'not-json';
            }

            public function getHeaders(): array
            {
                return [];
            }

            public function hasHeader(string $name): bool
            {
                return false;
            }

            public function getHeader(string $name): array
            {
                return [];
            }

            public function getHeaderLine(string $name): string
            {
                return '';
            }

            public function withHeader(string $name, string|iterable $value): WritableHeadersInterface
            {
                return $this;
            }

            public function withAddedHeader(string $name, string|iterable $value): WritableHeadersInterface
            {
                return $this;
            }

            public function withoutHeader(string $name): WritableHeadersInterface
            {
                return $this;
            }

            public function withDelay(int $delay): self
            {
                return $this;
            }

            public function ack(): void {}

            public function nack(string|\Stringable|\Throwable $message, bool $redelivery = false): void
            {
                $this->nacked = true;
            }

            public function requeue(string|\Stringable|\Throwable $message): void {}

            public function complete(): void {}

            public function fail(string|\Stringable|\Throwable $error, bool $requeue = false): void {}

            public function isCompleted(): bool
            {
                return $this->nacked;
            }

            public function isSuccessful(): bool
            {
                return false;
            }

            public function isFails(): bool
            {
                return $this->nacked;
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function getDriver(): Driver
            {
                return Driver::Memory;
            }
        };
        $consumer = new class($task) implements ConsumerInterface
        {
            private int $calls = 0;

            public function __construct(private ReceivedTaskInterface $task) {}

            public function waitTask(): ?ReceivedTaskInterface
            {
                return ++$this->calls === 1 ? $this->task : null;
            }
        };
        $processor = new JobProcessor(
            $this->createMock(ContainerInterface::class),
            new JobHandlerRegistry([]),
            $this->createMock(LifecycleManagerInterface::class),
            JobRetryConfiguration::fromArray([]),
        );
        $module = new RoadRunnerJobsModule($processor);
        $container = new class($module) implements ContainerInterface
        {
            public function __construct(private RoadRunnerJobsModule $module) {}

            public function instance(string $id, object $instance): void {}

            public function get(string $id): object
            {
                return $this->module;
            }

            public function has(string $id): bool
            {
                return true;
            }

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
        $adapter->start($container, static function (): never {
            throw new \LogicException('HTTP handler invoked.');
        });

        self::assertSame('jobs', $adapter->mode());
        self::assertSame('roadrunner', $adapter->getName());
        self::assertTrue($task->nacked);
    }

    public function test_stop_requests_roadrunner_worker_stop_while_jobs_loop_is_waiting(): void
    {
        $worker = $this->createMock(WorkerInterface::class);
        $worker->expects(self::once())->method('stop');
        $adapter = null;
        $consumer = new class($adapter) implements ConsumerInterface
        {
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

        $module = new RoadRunnerJobsModule(new JobProcessor(
            $this->createMock(ContainerInterface::class),
            new JobHandlerRegistry([]),
            $this->createMock(LifecycleManagerInterface::class),
            JobRetryConfiguration::fromArray([]),
        ));
        $container = new class($module) implements ContainerInterface
        {
            public function __construct(private RoadRunnerJobsModule $module) {}

            public function instance(string $id, object $instance): void {}

            public function get(string $id): object
            {
                return $this->module;
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function runHooks(string $attributeClass): void {}

            public function runLifecycleHooks(string $event): void {}

            public function resetScope(string $scope): void {}
        };
        $adapter->start($container, static function (): void {});
    }
}
