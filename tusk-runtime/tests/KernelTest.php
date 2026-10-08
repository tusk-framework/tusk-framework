<?php

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\ConsumerInterface;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Spiral\RoadRunner\Jobs\Task\WritableHeadersInterface;
use Spiral\RoadRunner\WorkerInterface;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Kernel;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;

final class KernelTestContainer implements ContainerInterface
{
    public function instance(string $id, object $instance): void {}

    public function get(string $id): object
    {
        return new class
        {
            public function handle(mixed $request): string
            {
                return 'handled';
            }
        };
    }

    public function has(string $id): bool
    {
        return true;
    }

    public function runHooks(string $attributeClass): void
    {
        throw new \LogicException('Kernel should use the lifecycle manager, not legacy hooks.');
    }

    public function runLifecycleHooks(string $event): void {}

    public function resetScope(string $scope): void {}
}

final class KernelTestLifecycleManager implements LifecycleManagerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function applicationStart(): void
    {
        $this->events[] = 'application.start';
    }

    public function workerStart(): void
    {
        $this->events[] = 'worker.start';
    }

    public function requestStart(): void
    {
        $this->events[] = 'request.start';
    }

    public function requestEnd(): void
    {
        $this->events[] = 'request.end';
    }

    public function jobStart(JobContext $job): void
    {
        $this->events[] = 'job.start';
    }

    public function jobEnd(?\Throwable $exception = null): void
    {
        $this->events[] = 'job.end';
    }

    public function workerStop(): void
    {
        $this->events[] = 'worker.stop';
    }

    public function applicationStop(): void
    {
        $this->events[] = 'application.stop';
    }

    public function wrap(callable $handler): callable
    {
        return function (...$arguments) use ($handler): mixed {
            $this->requestStart();

            try {
                return $handler(...$arguments);
            } finally {
                $this->requestEnd();
            }
        };
    }
}

final class KernelTestAdapter implements RuntimeAdapterInterface
{
    public bool $stopped = false;

    public function start(ContainerInterface $container, callable $requestHandler): void
    {
        $requestHandler('request');
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function getName(): string
    {
        return 'test';
    }
}

final class KernelTestRuntimeModule implements RuntimeModuleInterface
{
    /** @var list<string> */
    public array $events = [];

    public function name(): string
    {
        return 'test';
    }

    public function register(ContainerInterface $container): void
    {
        $this->events[] = 'register';
    }

    public function start(): void
    {
        $this->events[] = 'start';
    }

    public function stop(): void
    {
        $this->events[] = 'stop';
    }
}

final class KernelTestJobHandler implements JobHandlerInterface
{
    public function handle(JobContext $job): void {}
}

final class KernelTest extends TestCase
{
    public function test_kernel_owns_application_and_worker_lifecycle_around_adapter(): void
    {
        $lifecycle = new KernelTestLifecycleManager;
        $kernel = new Kernel(new KernelTestContainer, new KernelTestAdapter, $lifecycle);

        $kernel->start();

        self::assertSame([
            'application.start',
            'worker.start',
            'request.start',
            'request.end',
            'worker.stop',
            'application.stop',
        ], $lifecycle->events);
    }

    public function test_kernel_stops_lifecycle_even_when_adapter_fails(): void
    {
        $lifecycle = new KernelTestLifecycleManager;
        $adapter = new class implements RuntimeAdapterInterface
        {
            public function start(ContainerInterface $container, callable $requestHandler): void
            {
                throw new \RuntimeException('adapter failed');
            }

            public function stop(): void {}

            public function getName(): string
            {
                return 'failing';
            }
        };
        $kernel = new Kernel(new KernelTestContainer, $adapter, $lifecycle);

        $this->expectExceptionMessage('adapter failed');
        try {
            $kernel->start();
        } finally {
            self::assertSame([
                'application.start',
                'worker.start',
                'worker.stop',
                'application.stop',
            ], $lifecycle->events);
        }
    }

    public function test_kernel_starts_and_stops_runtime_modules_around_the_adapter(): void
    {
        $lifecycle = new KernelTestLifecycleManager;
        $module = new KernelTestRuntimeModule;
        $kernel = new Kernel(
            new KernelTestContainer,
            new KernelTestAdapter,
            $lifecycle,
            new RuntimeModuleRegistry([$module]),
        );

        $kernel->start();

        self::assertSame(['start', 'stop'], $module->events);
        self::assertSame([
            'application.start',
            'worker.start',
            'request.start',
            'request.end',
            'worker.stop',
            'application.stop',
        ], $lifecycle->events);
    }

    public function test_jobs_mode_shares_kernel_lifecycle_and_does_not_wrap_tasks_as_requests(): void
    {
        $events = [];
        $task = new class implements ReceivedTaskInterface
        {
            public bool $acked = false;

            public function getId(): string
            {
                return 'job-1';
            }

            public function getPipeline(): string
            {
                return 'default';
            }

            public function getName(): string
            {
                return 'test.job';
            }

            public function getPayload(): string
            {
                return '{"ok":true}';
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

            public function ack(): void
            {
                $this->acked = true;
            }

            public function nack(string|\Stringable|\Throwable $message, bool $redelivery = false): void {}

            public function requeue(string|\Stringable|\Throwable $message): void {}

            public function complete(): void {}

            public function fail(string|\Stringable|\Throwable $error, bool $requeue = false): void {}

            public function isCompleted(): bool
            {
                return $this->acked;
            }

            public function isSuccessful(): bool
            {
                return $this->acked;
            }

            public function isFails(): bool
            {
                return false;
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
        $container = new class($events) implements ContainerInterface
        {
            private array $instances = [];

            public function __construct(private array &$events) {}

            public function instance(string $id, object $instance): void
            {
                $this->instances[$id] = $instance;
            }

            public function get(string $id): object
            {
                if ($id === JobHandlerRegistry::class) {
                    return new JobHandlerRegistry(['test.job' => KernelTestJobHandler::class]);
                }
                if ($id === KernelTestJobHandler::class) {
                    $this->events[] = 'handle';

                    return new KernelTestJobHandler;
                }
                if (isset($this->instances[$id])) {
                    return $this->instances[$id];
                }
                throw new \RuntimeException('Unexpected container resolution: '.$id);
            }

            public function has(string $id): bool
            {
                return isset($this->instances[$id]);
            }

            public function runHooks(string $attributeClass): void {}

            public function runLifecycleHooks(string $event): void
            {
                $this->events[] = $event;
            }

            public function resetScope(string $scope): void {}
        };
        $lifecycle = new KernelTestLifecycleManager;
        $consumer = new class($events, $task) implements ConsumerInterface
        {
            private bool $delivered = false;

            public function __construct(private array &$events, private ReceivedTaskInterface $task) {}

            public function waitTask(): ?ReceivedTaskInterface
            {
                $this->events[] = 'wait';
                if ($this->delivered) {
                    return null;
                }
                $this->delivered = true;

                return $this->task;
            }
        };
        $worker = $this->createMock(WorkerInterface::class);
        $adapter = new RoadRunnerAdapter(
            mode: 'jobs',
            consumerFactory: static fn (WorkerInterface $worker) => $consumer,
            workerFactory: static fn () => $worker,
        );
        $kernel = new Kernel($container, $adapter, $lifecycle);

        $kernel->run(static function (): void {
            throw new \LogicException('HTTP handler must not run in Jobs mode.');
        });

        self::assertSame(['wait', 'handle', 'wait'], $events);
        self::assertTrue($task->acked);
        self::assertSame(['application.start', 'worker.start', 'job.start', 'job.end', 'worker.stop', 'application.stop'], $lifecycle->events);
    }
}
