<?php

namespace Tusk\Runtime\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Runtime\LifecycleManager;

final class LifecycleManagerContainer implements ContainerInterface
{
    public function instance(string $id, object $instance): void
    {
    }

    /** @var list<string> */
    public array $events = [];

    /** @var array<string, Throwable> */
    public array $failures = [];

    /** @var list<string> */
    public array $resetScopes = [];

    public function get(string $id): object
    {
        return new \stdClass;
    }

    public function has(string $id): bool
    {
        return false;
    }

    public function runHooks(string $attributeClass): void {}

    public function runLifecycleHooks(string $event): void
    {
        $this->events[] = $event;

        if (isset($this->failures[$event])) {
            throw $this->failures[$event];
        }
    }

    public function resetScope(string $scope): void
    {
        $this->resetScopes[] = $scope;
    }
}

final class LifecycleManagerTest extends TestCase
{
    public function test_transitions_and_request_wrapper_have_stable_order(): void
    {
        $container = new LifecycleManagerContainer;
        $manager = new LifecycleManager($container);

        $manager->applicationStart();
        $manager->workerStart();
        $wrapped = $manager->wrap(static fn (string $value): string => strtoupper($value));

        self::assertSame('OK', $wrapped('ok'));

        $manager->workerStop();
        $manager->applicationStop();

        self::assertSame([
            'application.start',
            'worker.start',
            'request.start',
            'request.end',
            'worker.stop',
            'application.stop',
        ], $container->events);
        self::assertSame(['request', 'worker'], $container->resetScopes);
    }

    public function test_repeated_transitions_are_idempotent(): void
    {
        $container = new LifecycleManagerContainer;
        $manager = new LifecycleManager($container);

        $manager->applicationStart();
        $manager->applicationStart();
        $manager->workerStart();
        $manager->workerStart();
        $manager->workerStop();
        $manager->workerStop();
        $manager->applicationStop();
        $manager->applicationStop();

        self::assertSame([
            'application.start',
            'worker.start',
            'worker.stop',
            'application.stop',
        ], $container->events);
        self::assertSame(['worker'], $container->resetScopes);
    }

    public function test_impossible_transitions_raise_logic_exception(): void
    {
        $container = new LifecycleManagerContainer;
        $manager = new LifecycleManager($container);

        $this->expectException(LogicException::class);
        $manager->workerStart();
    }

    public function test_handler_failure_preserves_original_exception_and_runs_request_end(): void
    {
        $container = new LifecycleManagerContainer;
        $manager = new LifecycleManager($container);
        $manager->applicationStart();
        $manager->workerStart();
        $expected = new RuntimeException('handler failed');

        try {
            $manager->wrap(static function () use ($expected): never {
                throw $expected;
            })();
            self::fail('The wrapped handler should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame($expected, $exception);
        }

        self::assertSame(['application.start', 'worker.start', 'request.start', 'request.end'], $container->events);
        self::assertSame(['request'], $container->resetScopes);
    }

    public function test_request_cleanup_failure_is_reported_when_handler_succeeds(): void
    {
        $container = new LifecycleManagerContainer;
        $cleanupFailure = new RuntimeException('cleanup failed');
        $container->failures['request.end'] = $cleanupFailure;
        $manager = new LifecycleManager($container);
        $manager->applicationStart();
        $manager->workerStart();

        try {
            $manager->wrap(static fn (): string => 'ok')();
            self::fail('Request cleanup should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame($cleanupFailure, $exception);
        }

        self::assertSame(['request'], $container->resetScopes);
    }

    public function test_stop_failure_does_not_leave_manager_in_started_state(): void
    {
        $container = new LifecycleManagerContainer;
        $container->failures['worker.stop'] = new RuntimeException('stop failed');
        $manager = new LifecycleManager($container);
        $manager->applicationStart();
        $manager->workerStart();

        try {
            $manager->workerStop();
            self::fail('The worker stop should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('stop failed', $exception->getMessage());
        }

        $manager->applicationStop();
        self::assertSame([
            'application.start',
            'worker.start',
            'worker.stop',
            'application.stop',
        ], $container->events);
        self::assertSame(['worker'], $container->resetScopes);
    }
}
