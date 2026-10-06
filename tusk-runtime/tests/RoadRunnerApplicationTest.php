<?php

namespace Tusk\Runtime\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Tusk\Contracts\Attributes\OnShutdown;
use Tusk\Contracts\Attributes\OnStart;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Modules\RuntimeModuleInterface;
use Tusk\Core\Container\Container;
use Tusk\Foundation\Application;
use Tusk\Runtime\Adapters\RoadRunnerAdapter;
use Tusk\Runtime\Kernel;
use Tusk\Runtime\Modules\RuntimeModuleRegistry;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;

class RoadRunnerApplicationTest extends TestCase
{
    public function test_application_uses_roadrunner_and_handles_repeated_requests_through_application(): void
    {
        $channel = $this->channel([new ServerRequest('GET', '/one'), new ServerRequest('GET', '/two')]);
        $container = new Container;
        $application = new RecordingApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));

        $application->runWorker();

        self::assertSame(['/one', '/two'], $application->paths);
        self::assertSame(['handled /one', 'handled /two'], array_map(
            static fn (ResponseInterface $response): string => (string) $response->getBody(),
            $channel->responses,
        ));
    }

    public function test_request_scope_is_reset_after_success_and_handler_exception(): void
    {
        $channel = $this->channel([new ServerRequest('GET', '/ok'), new ServerRequest('GET', '/fail'), new ServerRequest('GET', '/again')]);
        $container = new Container;
        $container->register(RequestState::class);
        $application = new RecordingApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));
        $application->failPath = '/fail';

        $application->runWorker();

        self::assertCount(3, $application->states);
        self::assertNotSame($application->states[0], $application->states[1]);
        self::assertNotSame($application->states[1], $application->states[2]);
        self::assertCount(2, $channel->responses);
        self::assertCount(1, $channel->errors);
        self::assertStringContainsString('handler failed', $channel->errors[0]);
    }

    public function test_shutdown_hooks_run_once_after_channel_termination_and_repeated_shutdown(): void
    {
        $channel = $this->channel([new ServerRequest('GET', '/ok')]);
        $container = new Container;
        $hooks = new LifecycleHooks;
        $container->instance(LifecycleHooks::class, $hooks);
        $application = new RecordingApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));

        $application->runWorker();
        $application->shutdown();
        $application->shutdown();

        self::assertSame(1, $hooks->starts);
        self::assertSame(1, $hooks->shutdowns);
    }

    public function test_application_registers_and_runs_configured_runtime_modules(): void
    {
        $channel = $this->channel([]);
        $container = new Container;
        $module = new RecordingRuntimeModule;
        $application = new RecordingApplication(
            __DIR__,
            $container,
            new HttpKernel($container, new Router),
            new RoadRunnerAdapter($channel),
            new RuntimeModuleRegistry([$module]),
        );

        $application->runWorker();

        self::assertSame(1, $module->registered);
        self::assertSame(1, $module->started);
        self::assertSame(1, $module->stopped);
    }

    public function test_shutdown_hooks_run_once_when_channel_fails(): void
    {
        $channel = $this->channel([new RuntimeException('channel closed')]);
        $container = new Container;
        $hooks = new LifecycleHooks;
        $container->instance(LifecycleHooks::class, $hooks);
        $application = new RecordingApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));

        $application->runWorker();

        self::assertSame(1, $hooks->shutdowns);
        self::assertCount(1, $channel->errors);
    }

    public function test_shutdown_during_request_stops_accepting_requests(): void
    {
        $channel = $this->channel([new ServerRequest('GET', '/first'), new ServerRequest('GET', '/second')]);
        $container = new Container;
        $hooks = new LifecycleHooks;
        $container->instance(LifecycleHooks::class, $hooks);
        $application = new ShutdownDuringRequestApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));

        $application->runWorker();

        self::assertSame(['/first'], $application->paths);
        self::assertCount(1, $channel->responses);
        self::assertSame(0, $application->shutdownsDuringRequest);
        self::assertSame(1, $hooks->shutdowns);
    }

    public function test_shutdown_hook_runs_once_for_service_registered_under_class_and_interface(): void
    {
        $channel = $this->channel([]);
        $container = new Container;
        $hooks = new LifecycleHooks;
        $container->instance(LifecycleHooks::class, $hooks);
        $container->instance(LifecycleHookContract::class, $hooks);
        $application = new RecordingApplication(__DIR__, $container, new HttpKernel($container, new Router), new RoadRunnerAdapter($channel));

        $application->runWorker();

        self::assertSame(1, $hooks->starts);
        self::assertSame(1, $hooks->shutdowns);
    }

    public function test_legacy_start_hook_can_register_http_kernel_before_it_is_resolved(): void
    {
        $channel = $this->channel([]);
        $container = new Container;
        $hooks = new HttpKernelRegistrationHook($container);
        $container->instance(HttpKernelRegistrationHook::class, $hooks);

        (new Kernel($container, new RoadRunnerAdapter($channel)))->start();

        self::assertSame(1, $hooks->starts);
        self::assertTrue($container->has(HttpKernel::class));
    }

    public function test_legacy_kernel_start_accepts_an_explicit_adapter(): void
    {
        $channel = $this->channel([]);
        $container = new Container;
        $container->instance(HttpKernel::class, new HttpKernel($container, new Router));
        $hooks = new LifecycleHooks;
        $container->instance(LifecycleHooks::class, $hooks);

        (new Kernel($container, new RoadRunnerAdapter($channel)))->start();

        self::assertSame(1, $hooks->starts);
        self::assertSame(1, $hooks->shutdowns);
    }

    /** @param list<ServerRequestInterface|\Throwable> $events */
    private function channel(array $events): TestRoadRunnerChannel
    {
        $worker = $this->createMock(WorkerInterface::class);
        $channel = new TestRoadRunnerChannel($worker, $events);
        $worker->method('error')->willReturnCallback(static function (string $error) use ($channel): void {
            $channel->errors[] = $error;
        });

        return $channel;
    }
}

class RecordingApplication extends Application
{
    /** @var list<string> */
    public array $paths = [];

    /** @var list<RequestState> */
    public array $states = [];

    public ?string $failPath = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $this->paths[] = $path;
        if ($this->container()->has(RequestState::class)) {
            $this->states[] = $this->container()->get(RequestState::class);
        }
        if ($path === $this->failPath) {
            throw new RuntimeException('handler failed');
        }

        return new Response(200, [], 'handled '.$path);
    }
}

class ShutdownDuringRequestApplication extends RecordingApplication
{
    public ?int $shutdownsDuringRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->shutdown();
        $this->shutdownsDuringRequest = $this->container()->get(LifecycleHooks::class)->shutdowns;

        return parent::handle($request);
    }
}

#[Service(scope: 'request')]
class RequestState {}

interface LifecycleHookContract {}

class LifecycleHooks implements LifecycleHookContract
{
    public int $starts = 0;

    public int $shutdowns = 0;

    #[OnStart]
    public function start(): void
    {
        $this->starts++;
    }

    #[OnShutdown]
    public function shutdown(): void
    {
        $this->shutdowns++;
    }
}

class HttpKernelRegistrationHook
{
    public int $starts = 0;

    public function __construct(private Container $container) {}

    #[OnStart]
    public function registerKernel(): void
    {
        $this->starts++;
        $this->container->instance(HttpKernel::class, new HttpKernel($this->container, new Router));
    }
}

final class RecordingRuntimeModule implements RuntimeModuleInterface
{
    public int $registered = 0;

    public int $started = 0;

    public int $stopped = 0;

    public function name(): string
    {
        return 'test.runtime';
    }

    public function register(ContainerInterface $container): void
    {
        $this->registered++;
    }

    public function start(): void
    {
        $this->started++;
    }

    public function stop(): void
    {
        $this->stopped++;
    }
}

class TestRoadRunnerChannel implements PSR7WorkerInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    /** @var list<string> */
    public array $errors = [];

    /** @param list<ServerRequestInterface|\Throwable> $events */
    public function __construct(private WorkerInterface $worker, private array $events) {}

    public function waitRequest(): ?ServerRequestInterface
    {
        $event = array_shift($this->events);
        if ($event instanceof \Throwable) {
            throw $event;
        }

        return $event;
    }

    public function respond(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }
}
