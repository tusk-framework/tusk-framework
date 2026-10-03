<?php

namespace Tusk\Runtime\Tests\Modules;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;

final class RuntimeModuleRegistryTest extends TestCase
{
    public function test_it_registers_and_starts_modules_in_declaration_order(): void
    {
        $events = [];
        $modules = [
            new RecordingRuntimeModule('first', $events),
            new RecordingRuntimeModule('second', $events),
        ];

        $registry = new RuntimeModuleRegistry($modules);
        $registry->register(new \Tusk\Core\Container\Container());
        $registry->start();

        self::assertSame([
            'register:first',
            'register:second',
            'start:first',
            'start:second',
        ], $events);
    }

    public function test_it_stops_all_modules_in_reverse_order_and_preserves_the_first_failure(): void
    {
        $events = [];
        $first = new RecordingRuntimeModule('first', $events);
        $second = new RecordingRuntimeModule('second', $events, new \RuntimeException('first stop failure'));
        $third = new RecordingRuntimeModule('third', $events);

        $registry = new RuntimeModuleRegistry([$first, $second, $third]);

        $this->expectExceptionMessage('first stop failure');

        try {
            $registry->stop();
        } finally {
            self::assertSame([
                'stop:third',
                'stop:second',
                'stop:first',
            ], $events);
        }
    }
}

final class RecordingRuntimeModule implements RuntimeModuleInterface
{
    public function __construct(
        private readonly string $moduleName,
        private array &$events,
        private readonly ?\Throwable $stopFailure = null,
    ) {}

    public function name(): string
    {
        return $this->moduleName;
    }

    public function register(ContainerInterface $container): void
    {
        $this->events[] = 'register:'.$this->moduleName;
    }

    public function start(): void
    {
        $this->events[] = 'start:'.$this->moduleName;
    }

    public function stop(): void
    {
        $this->events[] = 'stop:'.$this->moduleName;

        if ($this->stopFailure !== null) {
            throw $this->stopFailure;
        }
    }
}
