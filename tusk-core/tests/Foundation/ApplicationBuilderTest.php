<?php

namespace Tusk\Core\Tests\Foundation;

use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Health\HealthCheckRegistry;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfiguration;
use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceRuntime;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Config\Repository;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Contracts\Observability\WorkerLifecycleCheckpointInterface;
use Tusk\Foundation\Application;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;
use Tusk\Validation\Constraint\NotBlank;
use Tusk\Validation\CustomValidatorInterface;
use Tusk\Validation\CustomValidatorRegistry;
use Tusk\Validation\Metadata\ValidationMetadataRegistry;
use Tusk\Validation\Violation;

class ApplicationBuilderTest extends TestCase
{
    public function test_prepared_validators_run_with_constraints_and_do_not_leak_to_next_request(): void
    {
        file_put_contents($this->basePath.'/routes/web.php', <<<'PHP'
<?php
return static function (\Tusk\Web\Router\Router $router): void {
    $router->addRoute(['POST'], '/validated', [\Tusk\Core\Tests\Foundation\ValidationController::class, 'accepted']);
};
PHP);
        file_put_contents($this->basePath.'/bootstrap/providers.php', <<<'PHP'
<?php
return static function (\Tusk\Core\Container\Container $container): void {
    $container->instance(\Tusk\Core\Tests\Foundation\ValidationService::class, new \Tusk\Core\Tests\Foundation\ValidationService());
    $container->instance(\Tusk\Core\Tests\Foundation\ValidationController::class, new \Tusk\Core\Tests\Foundation\ValidationController());
};
PHP);
        $application = Application::configure($this->basePath)
            ->withRouting(web: 'routes/web.php')
            ->withProviders(['bootstrap/providers.php'])
            ->withValidator(ValidInput::class, RejectBlankInputValidator::class)
            ->create();

        $invalid = $application->handle((new ServerRequest('POST', '/validated'))->withHeader('Accept', 'application/json')->withParsedBody(['name' => ' ']));
        $problem = json_decode((string) $invalid->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['name' => [
            ['code' => 'not_blank', 'message' => 'This value should not be blank.'],
            ['code' => 'name.rejected', 'message' => 'Name is rejected.'],
        ]], $problem['errors']);

        $valid = $application->handle((new ServerRequest('POST', '/validated'))->withHeader('Accept', 'application/json')->withParsedBody(['name' => 'Ada']));
        self::assertSame(200, $valid->getStatusCode());
        self::assertSame(['name' => 'Ada'], json_decode((string) $valid->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/tusk-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/config', 0777, true);
        mkdir($this->basePath.'/routes');
        mkdir($this->basePath.'/bootstrap');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->basePath);
    }

    public function test_creates_application_with_base_path_and_core_bindings(): void
    {
        $builder = Application::configure($this->basePath);
        self::assertSame($this->basePath, $builder->basePath());

        $application = $builder->create();
        self::assertInstanceOf(Application::class, $application);
        self::assertSame($this->basePath, $application->basePath());
        self::assertSame($application, $application->container()->get(Application::class));
        self::assertInstanceOf(Router::class, $application->container()->get(Router::class));
        self::assertInstanceOf(HttpKernel::class, $application->container()->get(HttpKernel::class));
    }

    public function test_loads_only_direct_array_config_files_with_dotted_lookup(): void
    {
        file_put_contents($this->basePath.'/config/app.php', '<?php return ["name" => "Tusk", "nested" => ["enabled" => false, "none" => null]];');
        file_put_contents($this->basePath.'/config/ignored.txt', '<?php throw new \\RuntimeException("executed");');
        $application = Application::configure($this->basePath)->create();
        $config = $application->container()->get(Repository::class);

        self::assertSame('Tusk', $config->get('app.name'));
        self::assertSame(false, $config->get('app.nested.enabled'));
        self::assertTrue($config->has('app.nested.none'));
        self::assertNull($config->get('app.nested.none', 'fallback'));
        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
        self::assertSame(['app' => ['name' => 'Tusk', 'nested' => ['enabled' => false, 'none' => null]]], $config->all());
    }

    public function test_registers_configured_runtime_modules_before_the_application_is_used(): void
    {
        file_put_contents($this->basePath.'/config/runtime.php', <<<'PHP'
<?php

return [
    'runtime' => ['modules' => ['http']],
    'observability' => ['enabled' => false],
];
PHP);

        $application = Application::configure($this->basePath)->create();

        self::assertTrue($application->container()->has(RuntimeObservability::class));
        self::assertTrue($application->container()->has(TelemetryProviderInterface::class));
    }

    public function test_binds_validated_resilience_configuration_during_boot(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => ["payments" => ["retry" => ["max_attempts" => 3]]]];');

        $application = Application::configure($this->basePath)->create();

        self::assertSame(3, $application->container()->get(ResilienceConfiguration::class)->policy('payments')?->retry()['max_attempts']);
    }

    public function test_binds_one_runtime_with_effective_profile_before_providers_and_runtime_modules(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', <<<'PHP'
<?php
return [
    'policies' => ['payments' => ['retry' => ['max_attempts' => 2]]],
    'profiles' => ['testing' => ['policies' => ['payments' => ['bulkhead' => ['max_concurrent' => 2]]]]],
];
PHP);
        file_put_contents($this->basePath.'/bootstrap/providers.php', '<?php return static function ($container): void { $container->instance("provider.runtime", $container->get(\\Tusk\\Cloud\\Resilience\\Diagnostics\\ResilienceRuntime::class)); };');
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        try {
            $application = Application::configure($this->basePath)->withProviders(['bootstrap/providers.php'])->create();
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV='.$previous);
        }

        $container = $application->container();
        $runtime = $container->get(ResilienceRuntime::class);
        self::assertSame($runtime, $container->get('provider.runtime'));
        self::assertSame($container->get(ResilienceDiagnosticsRegistry::class), $container->get(ResilienceDiagnosticsRegistry::class));
        self::assertSame($container->get(EngineResilienceReporter::class), $container->get(WorkerLifecycleCheckpointInterface::class));
        $runtime->pipeline('payments');
        self::assertSame([['name' => 'payments', 'features' => ['retry', 'bulkhead']]], $runtime->diagnostics()->policies());
    }

    public function test_direct_factory_still_executes_without_engine_registration(): void
    {
        $factory = new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore);

        self::assertSame('direct', $factory->pipeline('legacy')->run(static fn (): string => 'direct'));
    }

    public function test_registers_local_resilience_readiness_after_configuration_validation(): void
    {
        $application = Application::configure($this->basePath)->create();

        $report = $application->container()->get(HealthCheckRegistry::class)->runChecks();

        self::assertSame('UP', $report['status']);
        self::assertSame('UP', $report['checks']['resilience_configuration']);
    }

    public function test_uses_app_env_as_the_resilience_profile_during_boot(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', <<<'PHP'
<?php

return [
    'policies' => ['payments' => ['retry' => ['max_attempts' => 2]]],
    'profiles' => ['testing' => ['policies' => ['payments' => ['retry' => ['max_attempts' => 4]]]]],
];
PHP);
        $previousProfile = getenv('APP_ENV');
        putenv('APP_ENV=testing');

        try {
            $application = Application::configure($this->basePath)->create();
        } finally {
            if ($previousProfile === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV='.$previousProfile);
            }
        }

        self::assertSame(4, $application->container()->get(ResilienceConfiguration::class)->policy('payments')?->retry()['max_attempts']);
    }

    public function test_invalid_resilience_configuration_fails_before_runtime_configuration_is_processed(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => ["payments" => ["timeout" => "private-value"]]];');
        file_put_contents($this->basePath.'/config/runtime.php', '<?php return ["runtime" => ["type" => "unsupported"]];');

        try {
            Application::configure($this->basePath)->create();
            self::fail('Invalid resilience configuration did not fail application boot.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resilience.policies.payments.timeout', $exception->getMessage());
            self::assertStringNotContainsString('private-value', $exception->getMessage());
        }
    }

    public function test_routes_and_providers_produce_psr7_response(): void
    {
        file_put_contents($this->basePath.'/routes/web.php', '<?php return static function (\\Tusk\\Web\\Router\\Router $router): void { $router->addRoute(["GET"], "/hello", [\\Tusk\\Core\\Tests\\Foundation\\BootstrapController::class, "hello"]); };');
        file_put_contents($this->basePath.'/bootstrap/providers.php', '<?php return static function (\\Tusk\\Core\\Container\\Container $container): void { $container->instance(\\Tusk\\Core\\Tests\\Foundation\\BootstrapController::class, new \\Tusk\\Core\\Tests\\Foundation\\BootstrapController()); };');
        $application = Application::configure($this->basePath)
            ->withRouting(web: 'routes/web.php')
            ->withProviders(['bootstrap/providers.php'])
            ->create();

        $response = $application->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello from Tusk', (string) $response->getBody());
    }

    public function test_discovers_and_registers_attribute_controllers_during_boot(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Discovered.php', <<<'PHP'
<?php
namespace TuskBuilderDiscovered;
#[\Tusk\Web\Attribute\Controller('/discovered')]
final class Discovered
{
    #[\Tusk\Web\Attribute\Get('/hello')]
    public function hello(): string { return 'discovered'; }
}
PHP);
        require $this->basePath.'/app/Controller/Discovered.php';

        $application = Application::configure($this->basePath)->create();

        $response = $application->handle(new ServerRequest('GET', '/discovered/hello'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('discovered', (string) $response->getBody());
        self::assertTrue($application->container()->has('TuskBuilderDiscovered\\Discovered'));
    }

    public function test_empty_controller_directory_and_explicit_routes_remain_supported(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/bootstrap/providers.php', '<?php return static function (\\Tusk\\Core\\Container\\Container $container): void { $container->instance(\\Tusk\\Core\\Tests\\Foundation\\BootstrapController::class, new \\Tusk\\Core\\Tests\\Foundation\\BootstrapController()); };');
        file_put_contents($this->basePath.'/routes/web.php', '<?php return static function (\\Tusk\\Web\\Router\\Router $router): void { $router->addRoute(["GET"], "/explicit", [\\Tusk\\Core\\Tests\\Foundation\\BootstrapController::class, "hello"]); };');

        $application = Application::configure($this->basePath)->withRouting(web: 'routes/web.php')->withProviders(['bootstrap/providers.php'])->create();

        self::assertSame('hello from Tusk', (string) $application->handle(new ServerRequest('GET', '/explicit'))->getBody());
        self::assertSame(404, $application->handle(new ServerRequest('GET', '/missing'))->getStatusCode());
    }

    public function test_configured_controller_directories_are_scanned_and_services_are_registered(): void
    {
        mkdir($this->basePath.'/custom/nested', 0777, true);
        file_put_contents($this->basePath.'/custom/nested/Configured.php', <<<'PHP'
<?php
namespace TuskBuilderConfigured;
#[\Tusk\Web\Attribute\Controller('/configured')]
final class Configured
{
    #[\Tusk\Web\Attribute\Get]
    public function index(): string { return 'configured'; }
}
PHP);
        require $this->basePath.'/custom/nested/Configured.php';

        $application = Application::configure($this->basePath)->withControllers('custom')->create();

        self::assertSame('configured', (string) $application->handle(new ServerRequest('GET', '/configured'))->getBody());
        self::assertTrue($application->container()->has('TuskBuilderConfigured\\Configured'));
    }

    public function test_discovered_controller_uses_constructor_injection_from_providers(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Injected.php', <<<'PHP'
<?php
namespace TuskBuilderInjected;
final class GreetingService
{
    public function message(): string { return 'injected greeting'; }
}
#[\Tusk\Web\Attribute\Controller('/injected')]
final class Injected
{
    public function __construct(private GreetingService $greetings) {}
    #[\Tusk\Web\Attribute\Get]
    public function index(): string { return $this->greetings->message(); }
}
PHP);
        require $this->basePath.'/app/Controller/Injected.php';
        file_put_contents($this->basePath.'/bootstrap/providers.php', <<<'PHP'
<?php
return static function (\Tusk\Core\Container\Container $container): void {
    $container->instance(\TuskBuilderInjected\GreetingService::class, new \TuskBuilderInjected\GreetingService());
};
PHP);

        $application = Application::configure($this->basePath)->withProviders(['bootstrap/providers.php'])->create();

        self::assertSame('injected greeting', (string) $application->handle(new ServerRequest('GET', '/injected'))->getBody());
    }

    public function test_discovered_controller_preserves_prototype_service_scope(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Prototype.php', <<<'PHP'
<?php
namespace TuskBuilderPrototype;
#[\Tusk\Contracts\Attributes\Service(scope: 'prototype')]
#[\Tusk\Web\Attribute\Controller('/prototype')]
final class Prototype
{
    private int $requests = 0;
    #[\Tusk\Web\Attribute\Get]
    public function index(): string { return (string) ++$this->requests; }
}
PHP);
        require $this->basePath.'/app/Controller/Prototype.php';

        $application = Application::configure($this->basePath)->create();

        $first = $application->handle(new ServerRequest('GET', '/prototype'));
        $second = $application->handle(new ServerRequest('GET', '/prototype'));

        self::assertSame('1', (string) $first->getBody());
        self::assertSame('1', (string) $second->getBody());
    }

    public function test_discovered_route_collision_with_explicit_route_fails_at_boot(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Collision.php', <<<'PHP'
<?php
namespace TuskBuilderCollision;
#[\Tusk\Web\Attribute\Controller]
final class Collision
{
    #[\Tusk\Web\Attribute\Get('/collision')]
    public function index(): string { return 'attribute'; }
}
PHP);
        require $this->basePath.'/app/Controller/Collision.php';
        file_put_contents($this->basePath.'/routes/web.php', <<<'PHP'
<?php
return static function (\Tusk\Web\Router\Router $router): void {
    $router->addRoute(['GET'], '/collision', static fn (): string => 'explicit');
};
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate controller route GET /collision');
        Application::configure($this->basePath)->withRouting(web: 'routes/web.php')->create();
    }

    public function test_discovered_controller_binds_route_scalar_and_flat_readonly_dto(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Typed.php', <<<'PHP'
<?php
namespace TuskBuilderTyped;
final readonly class Input
{
    public function __construct(public string $name) {}
}
#[\Tusk\Web\Attribute\Controller('/typed')]
final class Typed
{
    #[\Tusk\Web\Attribute\Get('/{id}')]
    public function show(int $id, Input $input): array { return ['id' => $id, 'name' => $input->name]; }
}
PHP);
        require $this->basePath.'/app/Controller/Typed.php';

        $application = Application::configure($this->basePath)->create();
        $request = (new ServerRequest('GET', '/typed/42'))->withParsedBody(['name' => 'Tusk']);
        $response = $application->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 42, 'name' => 'Tusk'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_discovery_handles_multiple_classes_and_anonymous_classes(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Multiple.php', <<<'PHP'
<?php
namespace TuskBuilderMultiple;
$anonymous = new class {};
#[\Tusk\Web\Attribute\Controller('/multiple')]
final class First
{
    #[\Tusk\Web\Attribute\Get('/first')]
    public function first(): string { return 'first'; }
}
#[\Tusk\Web\Attribute\Controller('/multiple')]
final class Second
{
    #[\Tusk\Web\Attribute\Get('/second')]
    public function second(): string { return 'second'; }
}
PHP);
        require $this->basePath.'/app/Controller/Multiple.php';

        $application = Application::configure($this->basePath)->create();

        self::assertSame('first', (string) $application->handle(new ServerRequest('GET', '/multiple/first'))->getBody());
        self::assertSame('second', (string) $application->handle(new ServerRequest('GET', '/multiple/second'))->getBody());
    }

    public function test_controller_discovery_ignores_symlinked_php_files(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/OutsideController.php', <<<'PHP'
<?php
namespace TuskBuilderSymlink;
#[\Tusk\Web\Attribute\Controller('/outside')]
final class OutsideController
{
    #[\Tusk\Web\Attribute\Get]
    public function index(): string { return 'outside'; }
}
PHP);
        if (! @symlink($this->basePath.'/OutsideController.php', $this->basePath.'/app/Controller/LinkedController.php')) {
            self::markTestSkipped('The current Windows environment does not permit creating file symlinks.');
        }

        $application = Application::configure($this->basePath)->create();

        self::assertSame(404, $application->handle(new ServerRequest('GET', '/outside'))->getStatusCode());
    }

    public function test_duplicate_discovered_routes_fail_with_a_boot_diagnostic(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        foreach (['First', 'Second'] as $class) {
            file_put_contents($this->basePath.'/app/Controller/'.$class.'.php', '<?php namespace TuskBuilderDuplicate; #[\\Tusk\\Web\\Attribute\\Controller] final class '.$class.' { #[\\Tusk\\Web\\Attribute\\Get("/same")] public function index(): string { return "'.$class.'"; } }');
            require $this->basePath.'/app/Controller/'.$class.'.php';
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('/same');
        Application::configure($this->basePath)->create();
    }

    public function test_missing_route_file_fails_at_create(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('routes/missing.php');
        Application::configure($this->basePath)->withRouting(web: 'routes/missing.php')->create();
    }

    public function test_missing_provider_file_fails_at_create(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bootstrap/missing.php');
        Application::configure($this->basePath)->withProviders(['bootstrap/missing.php'])->create();
    }

    public function test_with_jobs_compiles_registry_and_registers_handler(): void
    {
        mkdir($this->basePath.'/app/Jobs', 0777, true);
        file_put_contents($this->basePath.'/app/Jobs/Welcome.php', <<<'PHP'
<?php
namespace TuskBuilderJobs;
#[\Tusk\Contracts\Attributes\AsJob('mail.welcome')]
final class Welcome implements \Tusk\Contracts\Runtime\Jobs\JobHandlerInterface
{
    public function handle(\Tusk\Contracts\Runtime\Jobs\JobContext $job): void {}
}
PHP);

        try {
            $application = Application::configure($this->basePath)->withJobs('app/Jobs')->create();
            $container = $application->container();
            self::assertSame('TuskBuilderJobs\\Welcome', $container->get(JobHandlerRegistry::class)->handlerClass('mail.welcome'));
            self::assertTrue($container->has('TuskBuilderJobs\\Welcome'));
            self::assertSame('job', $container->export()['scopes']['TuskBuilderJobs\\Welcome']);
        } finally {
            unlink($this->basePath.'/app/Jobs/Welcome.php');
            rmdir($this->basePath.'/app/Jobs');
            rmdir($this->basePath.'/app');
        }
    }

    public function test_with_jobs_enables_queue_capability_without_replacing_runtime_modules(): void
    {
        mkdir($this->basePath.'/app/Jobs', 0777, true);
        file_put_contents($this->basePath.'/config/runtime.php', <<<'PHP'
<?php

return ['runtime' => ['modules' => ['http']], 'observability' => ['enabled' => false]];
PHP);

        $application = Application::configure($this->basePath)
            ->withJobs('app/Jobs')
            ->create();

        self::assertSame(['http', 'capabilities.jobs'], $application->container()->get(RuntimeConfiguration::class)->modules());
        self::assertTrue($application->container()->has(TelemetryProviderInterface::class));

        rmdir($this->basePath.'/app/Jobs');
        rmdir($this->basePath.'/app');
    }

    public function test_with_jobs_preserves_runtime_validation_for_malformed_configuration(): void
    {
        mkdir($this->basePath.'/app/Jobs', 0777, true);
        file_put_contents($this->basePath.'/config/runtime.php', '<?php return ["modules" => "invalid"];');

        try {
            Application::configure($this->basePath)->withJobs('app/Jobs')->create();
            self::fail('Expected malformed runtime modules to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The runtime modules configuration must be an array.', $exception->getMessage());
        } finally {
            unlink($this->basePath.'/config/runtime.php');
            rmdir($this->basePath.'/app/Jobs');
            rmdir($this->basePath.'/app');
        }
    }

    public function test_with_jobs_accepts_absolute_scan_root(): void
    {
        mkdir($this->basePath.'/app/Jobs', 0777, true);
        file_put_contents($this->basePath.'/app/Jobs/Absolute.php', <<<'PHP'
<?php
namespace TuskBuilderAbsoluteJobs;
#[\Tusk\Contracts\Attributes\AsJob('mail.absolute')]
final class Absolute implements \Tusk\Contracts\Runtime\Jobs\JobHandlerInterface
{
    public function handle(\Tusk\Contracts\Runtime\Jobs\JobContext $job): void {}
}
PHP);

        try {
            $application = Application::configure($this->basePath)->withJobs($this->basePath.'/app/Jobs')->create();
            self::assertSame('TuskBuilderAbsoluteJobs\\Absolute', $application->container()->get(JobHandlerRegistry::class)->handlerClass('mail.absolute'));
        } finally {
            unlink($this->basePath.'/app/Jobs/Absolute.php');
            rmdir($this->basePath.'/app/Jobs');
            rmdir($this->basePath.'/app');
        }
    }

    public function test_explicit_route_metadata_is_compiled_and_sealed_before_create_returns(): void
    {
        file_put_contents($this->basePath.'/routes/web.php', <<<'PHP'
<?php
return static function (\Tusk\Web\Router\Router $router): void {
    $router->addRoute(['POST'], '/input', [\Tusk\Core\Tests\Foundation\ValidationController::class, 'submit']);
};
PHP);

        $application = Application::configure($this->basePath)->withRouting(web: 'routes/web.php')->create();
        $registry = $application->container()->get(ValidationMetadataRegistry::class);
        self::assertSame(['name'], $registry->metadataFor(ValidInput::class)->fields());
        $this->expectException(\LogicException::class);
        $registry->register('LateDto', $registry->metadataFor(ValidInput::class));
    }

    public function test_invalid_explicit_route_dto_metadata_aborts_create(): void
    {
        file_put_contents($this->basePath.'/routes/web.php', <<<'PHP'
<?php
return static function (\Tusk\Web\Router\Router $router): void {
    $router->addRoute(['POST'], '/invalid', [\Tusk\Core\Tests\Foundation\ValidationController::class, 'invalid']);
};
PHP);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string');
        Application::configure($this->basePath)->withRouting(web: 'routes/web.php')->create();
    }

    public function test_invalid_discovered_route_dto_metadata_aborts_create(): void
    {
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/Invalid.php', <<<'PHP'
<?php
namespace TuskBuilderInvalidMetadata;
#[\Tusk\Web\Attribute\Controller('/invalid')]
final class Invalid
{
    #[\Tusk\Web\Attribute\Post]
    public function submit(\Tusk\Core\Tests\Foundation\InvalidInput $input): void {}
}
PHP);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string');
        Application::configure($this->basePath)->create();
    }

    public function test_custom_validators_resolve_in_order_with_provider_dependencies_and_only_for_their_dto(): void
    {
        file_put_contents($this->basePath.'/bootstrap/providers.php', <<<'PHP'
<?php
return static function (\Tusk\Core\Container\Container $container): void {
    $container->instance(\Tusk\Core\Tests\Foundation\ValidationService::class, new \Tusk\Core\Tests\Foundation\ValidationService());
};
PHP);

        $application = Application::configure($this->basePath)
            ->withProviders(['bootstrap/providers.php'])
            ->withValidator(ValidInput::class, InjectedInputValidator::class)
            ->withValidator(ValidInput::class, SecondInputValidator::class)
            ->create();
        $registry = $application->container()->get(CustomValidatorRegistry::class);

        self::assertSame([InjectedInputValidator::class, SecondInputValidator::class], array_map(
            static fn (CustomValidatorInterface $validator): string => $validator::class,
            $registry->for(ValidInput::class),
        ));
        self::assertSame('available', $registry->for(ValidInput::class)[0]->service->value);
        self::assertSame($registry->for(ValidInput::class)[0], $registry->for(ValidInput::class)[0]);
        self::assertSame([], $registry->for(InvalidInput::class));
    }

    public function test_custom_validator_prototype_scope_is_resolved_for_each_validation(): void
    {
        PrototypeInputValidator::$instancesCreated = 0;
        $application = Application::configure($this->basePath)
            ->withValidator(ValidInput::class, PrototypeInputValidator::class)
            ->create();
        $registry = $application->container()->get(CustomValidatorRegistry::class);

        self::assertSame(0, PrototypeInputValidator::$instancesCreated);
        self::assertNotSame(
            $registry->for(ValidInput::class)[0],
            $registry->for(ValidInput::class)[0],
        );
        self::assertSame(2, PrototypeInputValidator::$instancesCreated);
    }

    public function test_custom_validator_request_scope_uses_a_fresh_instance_after_scope_reset(): void
    {
        RequestScopedInputValidator::$instancesCreated = 0;
        $application = Application::configure($this->basePath)
            ->withValidator(ValidInput::class, RequestScopedInputValidator::class)
            ->create();
        $registry = $application->container()->get(CustomValidatorRegistry::class);
        $container = $application->container();
        self::assertSame(0, RequestScopedInputValidator::$instancesCreated);

        $container->runLifecycleHooks('application.start');
        $container->runLifecycleHooks('worker.start');

        self::assertSame(0, RequestScopedInputValidator::$instancesCreated);
        $first = $registry->for(ValidInput::class)[0];
        self::assertSame(1, RequestScopedInputValidator::$instancesCreated);

        $container->resetScope('request');

        self::assertNotSame($first, $registry->for(ValidInput::class)[0]);
        self::assertSame(2, RequestScopedInputValidator::$instancesCreated);
    }

    public function test_custom_validator_worker_scope_uses_a_fresh_instance_after_scope_reset(): void
    {
        WorkerScopedInputValidator::$instancesCreated = 0;
        $application = Application::configure($this->basePath)
            ->withValidator(ValidInput::class, WorkerScopedInputValidator::class)
            ->create();
        $registry = $application->container()->get(CustomValidatorRegistry::class);
        $container = $application->container();
        $first = $registry->for(ValidInput::class)[0];

        self::assertSame($first, $registry->for(ValidInput::class)[0]);
        $container->resetScope('worker');

        self::assertNotSame($first, $registry->for(ValidInput::class)[0]);
        self::assertSame(2, WorkerScopedInputValidator::$instancesCreated);
    }

    public function test_duplicate_custom_validator_registration_is_rejected(): void
    {
        $builder = Application::configure($this->basePath)->withValidator(ValidInput::class, SecondInputValidator::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');
        $builder->withValidator(ValidInput::class, SecondInputValidator::class);
    }

    public function test_invalid_custom_validator_fails_during_create_with_class_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(BootstrapController::class);
        Application::configure($this->basePath)
            ->withValidator(ValidInput::class, BootstrapController::class)
            ->create();
    }

    public function test_abstract_custom_validator_fails_during_create_without_instantiation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AbstractInputValidator::class);
        Application::configure($this->basePath)
            ->withValidator(ValidInput::class, AbstractInputValidator::class)
            ->create();
    }

    public function test_custom_validator_with_lifecycle_hooks_fails_during_create(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not declare lifecycle hooks');
        Application::configure($this->basePath)
            ->withValidator(ValidInput::class, HookedRequestScopedInputValidator::class)
            ->create();
    }
}

class BootstrapController
{
    public function hello(): string
    {
        return 'hello from Tusk';
    }
}

final readonly class ValidInput
{
    public function __construct(#[NotBlank] public string $name) {}
}

final readonly class InvalidInput
{
    public function __construct(#[NotBlank] public int $count) {}
}

final class ValidationController
{
    public function submit(ValidInput $input): void {}

    public function accepted(ValidInput $input): array { return ['name' => $input->name]; }

    public function invalid(InvalidInput $input): void {}
}

final class RejectBlankInputValidator implements CustomValidatorInterface
{
    public function __construct(private ValidationService $service) {}

    public function validate(object $value): iterable
    {
        if ($this->service->value === 'available' && $value instanceof ValidInput && trim($value->name) === '') {
            yield new Violation('name', 'name.rejected', 'Name is rejected.');
        }
    }
}

final readonly class ValidationService
{
    public string $value;

    public function __construct()
    {
        $this->value = 'available';
    }
}

final class InjectedInputValidator implements CustomValidatorInterface
{
    public function __construct(public ValidationService $service) {}

    public function validate(object $value): iterable
    {
        return [];
    }
}

final class SecondInputValidator implements CustomValidatorInterface
{
    public function validate(object $value): iterable
    {
        return [];
    }
}

#[\Tusk\Contracts\Attributes\Service(scope: 'prototype')]
final class PrototypeInputValidator implements CustomValidatorInterface
{
    public static int $instancesCreated = 0;

    public function __construct()
    {
        self::$instancesCreated++;
    }

    public function validate(object $value): iterable
    {
        return [];
    }
}

#[\Tusk\Contracts\Attributes\Service(scope: 'request')]
final class RequestScopedInputValidator implements CustomValidatorInterface
{
    public static int $instancesCreated = 0;

    public function __construct()
    {
        self::$instancesCreated++;
    }

    public function validate(object $value): iterable
    {
        return [];
    }
}

#[\Tusk\Contracts\Attributes\Service(scope: 'request')]
abstract class AbstractInputValidator implements CustomValidatorInterface {}

#[\Tusk\Contracts\Attributes\Service(scope: 'request')]
final class HookedRequestScopedInputValidator implements CustomValidatorInterface
{
    #[\Tusk\Contracts\Attributes\OnWorkerStart]
    public function startWorker(): void {}

    public function validate(object $value): iterable
    {
        return [];
    }
}

#[\Tusk\Contracts\Attributes\Service(scope: 'worker')]
final class WorkerScopedInputValidator implements CustomValidatorInterface
{
    public static int $instancesCreated = 0;

    public function __construct()
    {
        self::$instancesCreated++;
    }

    public function validate(object $value): iterable
    {
        return [];
    }
}
