<?php

namespace Tusk\Core\Tests\Foundation;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Config\Repository;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use Tusk\Foundation\Application;
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\Observability\RuntimeObservability;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;

class ApplicationBuilderTest extends TestCase
{
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
        foreach (['config/app.php', 'config/runtime.php', 'config/ignored.txt', 'routes/web.php', 'bootstrap/providers.php'] as $file) {
            if (is_file($this->basePath.'/'.$file)) {
                unlink($this->basePath.'/'.$file);
            }
        }
        foreach (['config', 'routes', 'bootstrap'] as $directory) {
            rmdir($this->basePath.'/'.$directory);
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
        } catch (\InvalidArgumentException $exception) {
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
}

class BootstrapController
{
    public function hello(): string
    {
        return 'hello from Tusk';
    }
}
