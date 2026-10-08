<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Attributes\AsJob;
use Tusk\Contracts\Attributes\OnJobEnd;
use Tusk\Contracts\Attributes\OnJobStart;
use Tusk\Contracts\Attributes\OnRequestEnd;
use Tusk\Contracts\Attributes\OnRequestStart;
use Tusk\Contracts\Attributes\OnWorkerStart;
use Tusk\Contracts\Attributes\OnWorkerStop;
use Tusk\Contracts\Attributes\Service;
use Tusk\Core\Container\Container;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;

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

#[AsJob('test.lifecycle')]
final class LifecycleJobHandler implements JobHandlerInterface
{
    public array $events = [];

    #[OnJobStart]
    public function start(): void { $this->events[] = 'start'; }

    public function handle(JobContext $job): void { $this->events[] = 'handle'; }

    #[OnJobEnd]
    public function end(): void { $this->events[] = 'end'; }
}

final class LifecycleHooksTest extends TestCase
{
    public function test_job_hooks_are_distinct_and_job_scope_is_reset_between_deliveries(): void
    {
        $container = new Container;
        $container->register(LifecycleJobHandler::class);
        $container->runLifecycleHooks('job.start');
        $first = $container->get(LifecycleJobHandler::class);
        $first->handle(new JobContext('1', 'queue', 'test.lifecycle', '{}', []));
        $container->runLifecycleHooks('job.end');

        self::assertSame(['start', 'handle', 'end'], $first->events);
        $container->resetScope('job');
        $container->runLifecycleHooks('request.start');
        $container->runLifecycleHooks('job.start');

        $second = $container->get(LifecycleJobHandler::class);
        self::assertNotSame($first, $second);
        self::assertSame(['start'], $second->events);
    }

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
