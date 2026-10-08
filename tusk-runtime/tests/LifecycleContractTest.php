<?php

namespace Tusk\Runtime\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tusk\Contracts\Attributes\OnRequestEnd;
use Tusk\Contracts\Attributes\OnRequestStart;
use Tusk\Contracts\Attributes\OnWorkerStart;
use Tusk\Contracts\Attributes\OnWorkerStop;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\LifecycleEvent;
use Tusk\Contracts\Runtime\LifecycleManagerInterface;

final class LifecycleContractTest extends TestCase
{
    public function test_lifecycle_events_are_stable_string_values(): void
    {
        self::assertSame('application.start', LifecycleEvent::APPLICATION_START->value);
        self::assertSame('worker.start', LifecycleEvent::WORKER_START->value);
        self::assertSame('request.start', LifecycleEvent::REQUEST_START->value);
        self::assertSame('request.end', LifecycleEvent::REQUEST_END->value);
        self::assertSame('worker.stop', LifecycleEvent::WORKER_STOP->value);
        self::assertSame('application.stop', LifecycleEvent::APPLICATION_STOP->value);
    }

    public function test_lifecycle_manager_exposes_transport_neutral_transitions(): void
    {
        $methods = (new ReflectionClass(LifecycleManagerInterface::class))->getMethods();

        self::assertSame(
            [
                'applicationStart',
                'workerStart',
                'requestStart',
                'requestEnd',
                'jobStart',
                'jobEnd',
                'workerStop',
                'applicationStop',
                'wrap',
            ],
            array_map(static fn ($method): string => $method->getName(), $methods),
        );
    }

    public function test_new_hook_attributes_target_methods_only(): void
    {
        foreach ([OnWorkerStart::class, OnWorkerStop::class, OnRequestStart::class, OnRequestEnd::class] as $attribute) {
            $reflection = new ReflectionClass($attribute);

            self::assertSame(\Attribute::TARGET_METHOD, $reflection->getAttributes(\Attribute::class)[0]->getArguments()[0]);
        }
    }

    public function test_container_contract_exposes_compiled_lifecycle_hooks(): void
    {
        self::assertNotNull((new ReflectionClass(ContainerInterface::class))->getMethod('runLifecycleHooks'));
    }
}
