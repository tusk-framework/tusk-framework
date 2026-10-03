<?php

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Contracts\Runtime\RuntimeAdapterInterface;
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
}
