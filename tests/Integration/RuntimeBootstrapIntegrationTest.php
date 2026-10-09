<?php

namespace Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\ResiliencePipelineBuilder;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Observability\SpanInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityRegistryInterface;
use Tusk\Core\Container\Container;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\RuntimeModuleFactory;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;

final class RuntimeBootstrapIntegrationTest extends TestCase
{
    public function test_a_legacy_bootstrap_returning_null_keeps_the_default_runtime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tusk_bootstrap_');
        self::assertNotFalse($path);
        file_put_contents($path, "<?php\nreturn null;\n");

        try {
            $result = require $path;
            $configuration = RuntimeConfiguration::fromArray(is_array($result) ? $result : []);

            self::assertSame('roadrunner', $configuration->adapter());
            self::assertSame(['http'], $configuration->modules());
        } finally {
            unlink($path);
        }
    }

    public function test_declared_capability_modules_register_the_provider_neutral_registry(): void
    {
        $configuration = RuntimeConfiguration::fromArray([
            'runtime' => ['modules' => ['capabilities.kv', 'capabilities.metrics', 'grpc']],
        ]);
        $modules = RuntimeModuleFactory::fromConfiguration($configuration);
        $container = new Container;

        $modules->register($container);

        self::assertTrue($container->has(CapabilityRegistryInterface::class));
    }

    public function test_reused_worker_pipeline_does_not_leak_context_between_requests(): void
    {
        $telemetry = new ResilienceContextTelemetry;
        $factory = new ResiliencePipelineFactory(new SystemClock, new InMemoryStateStore, telemetry: $telemetry);
        $controller = new ResilienceContextController($factory->pipeline('worker-operation'));
        $container = new Container;
        $container->instance(ResilienceContextController::class, $controller);
        $router = new Router;
        $router->addRoute(['GET'], '/run', [ResilienceContextController::class, 'run']);
        $kernel = new HttpKernel($container, $router);

        $first = $kernel->handle((new ServerRequest('GET', '/run'))->withQueryParams([
            'operation' => 'orders.read',
            'request_id' => 'request-one',
        ]));
        $second = $kernel->handle((new ServerRequest('GET', '/run'))->withQueryParams([
            'operation' => 'inventory.update',
            'request_id' => 'request-two',
        ]));

        self::assertSame(['operation' => 'orders.read', 'request_id' => 'request-one'], json_decode((string) $first->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['operation' => 'inventory.update', 'request_id' => 'request-two'], json_decode((string) $second->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([
            ['orders.read', ['request_id' => 'request-one']],
            ['inventory.update', ['request_id' => 'request-two']],
        ], $controller->observedContexts);
        self::assertSame([
            ['tusk.resilience.operations', ['outcome' => 'success']],
            ['tusk.resilience.operations', ['outcome' => 'success']],
        ], $telemetry->increments);
        self::assertCount(2, $telemetry->observations);
        self::assertSame(
            [
                ['outcome' => 'success'],
                ['outcome' => 'success'],
            ],
            array_column($telemetry->observations, 2),
        );
    }
}

final class ResilienceContextTelemetry implements TelemetryProviderInterface
{
    /** @var list<array{string, array<string, scalar|null>}> */
    public array $increments = [];

    /** @var list<array{string, float, array<string, scalar|null>}> */
    public array $observations = [];

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        throw new \LogicException('This test does not create spans.');
    }

    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->increments[] = [$name, $attributes];
    }

    public function observe(string $name, float $value, array $attributes = []): void
    {
        $this->observations[] = [$name, $value, $attributes];
    }

    public function flush(): void {}

    public function shutdown(): void {}
}

final class ResilienceContextController
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $observedContexts = [];

    public function __construct(private readonly ResiliencePipelineBuilder $pipeline) {}

    public function run(Request $request): Response
    {
        $operation = (string) $request->get('operation');
        $requestId = (string) $request->get('request_id');
        $context = OperationContext::create($operation, metadata: ['request_id' => $requestId]);
        $result = $this->pipeline->run(function (OperationContext $operationContext): array {
            $this->observedContexts[] = [$operationContext->operation(), $operationContext->metadata()];

            return [
                'operation' => $operationContext->operation(),
                'request_id' => $operationContext->metadata()['request_id'],
            ];
        }, $context);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($result, JSON_THROW_ON_ERROR));
    }
}
