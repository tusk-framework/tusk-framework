<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Attributes\OnRequestEnd;
use Tusk\Contracts\Attributes\OnRequestStart;
use Tusk\Contracts\Attributes\OnWorkerStart;
use Tusk\Contracts\Attributes\OnWorkerStop;
use Tusk\Contracts\Attributes\Service;
use Tusk\Core\Container\Container;

#[Service(scope: 'worker')]
final class LifecycleHookService
{
    /** @var list<string> */
    public array $events = [];

    #[OnWorkerStart]
    public function startWorker(): void
    {
        $this->events[] = 'worker.start';
    }

    #[OnWorkerStop]
    public function stopWorker(): void
    {
        $this->events[] = 'worker.stop';
    }

    #[OnRequestStart]
    public function startRequest(): void
    {
        $this->events[] = 'request.start';
    }

    #[OnRequestEnd]
    public function endRequest(): void
    {
        $this->events[] = 'request.end';
    }
}

#[Service(scope: 'worker')]
final class FailingTeardownService
{
    /** @var list<string> */
    public array $events = [];

    #[OnWorkerStop]
    public function first(): void
    {
        $this->events[] = 'first';
        throw new \RuntimeException('first teardown failed');
    }

    #[OnWorkerStop]
    public function second(): void
    {
        $this->events[] = 'second';
    }
}

final class LifecycleHooksTest extends TestCase
{
    public function test_interpreted_container_resolves_hooks_lazily_and_invokes_them(): void
    {
        $container = new Container();
        $container->register(LifecycleHookService::class);

        self::assertTrue($container->has(LifecycleHookService::class));

        $container->runLifecycleHooks('worker.start');
        $container->runLifecycleHooks('request.start');
        $container->runLifecycleHooks('request.end');
        $container->runLifecycleHooks('worker.stop');

        $service = $container->get(LifecycleHookService::class);
        self::assertSame([
            'worker.start',
            'request.start',
            'request.end',
            'worker.stop',
        ], $service->events);
    }

    public function test_reset_scope_removes_worker_instances_without_reflection(): void
    {
        $container = new Container();
        $container->register(LifecycleHookService::class);
        $first = $container->get(LifecycleHookService::class);

        $container->resetScope('worker');

        self::assertNotSame($first, $container->get(LifecycleHookService::class));
    }

    public function test_teardown_continues_after_a_hook_failure(): void
    {
        $container = new Container();
        $container->register(FailingTeardownService::class);

        try {
            $container->runLifecycleHooks('worker.stop');
            self::fail('The first teardown failure should be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('first teardown failed', $exception->getMessage());
        }

        self::assertSame(['first', 'second'], $container->get(FailingTeardownService::class)->events);
    }
}
