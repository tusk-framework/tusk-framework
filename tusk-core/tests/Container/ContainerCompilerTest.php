<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Contracts\Attributes\OnJobEnd;
use Tusk\Contracts\Attributes\OnJobStart;
use Tusk\Contracts\Runtime\Jobs\JobContext;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;
use Tusk\Core\Container\ContainerCompiler;
use Tusk\Core\Container\ServiceScanner;

final class CompiledLifecycleJob implements JobHandlerInterface
{
    public array $events = [];

    #[OnJobStart]
    public function start(): void { $this->events[] = 'start'; }

    public function handle(JobContext $job): void {}

    #[OnJobEnd]
    public function end(): void { $this->events[] = 'end'; }
}

class ContainerCompilerTest extends TestCase
{
    public function test_scanner_and_compiled_container_keep_business_alias_but_omit_shared_job_alias(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'tusk-job-');
        $path = $source.'.php';
        rename($source, $path);
        $namespace = 'TuskJobScan'.bin2hex(random_bytes(4));
        file_put_contents($path, sprintf(<<<'PHP'
<?php
namespace %s;
interface MailJobContract {}
#[\Tusk\Contracts\Attributes\AsJob('mail.welcome')]
final class MailJob implements MailJobContract, \Tusk\Contracts\Runtime\Jobs\JobHandlerInterface
{
    public function handle(\Tusk\Contracts\Runtime\Jobs\JobContext $job): void {}
}
PHP, $namespace));

        try {
            $definitions = (new ServiceScanner)->scan([$path]);
            $jobDefinition = $definitions[$namespace.'\MailJob'];
            self::assertSame([$namespace.'\MailJobContract'], $jobDefinition['interfaces']);
            $compiled = (new ContainerCompiler)->compile($definitions, $namespace.'Compiled', 'Container');
            eval(substr($compiled, 5));
            $containerClass = $namespace.'Compiled\Container';
            $container = new $containerClass;
            self::assertTrue($container->has($namespace.'\MailJobContract'));
            self::assertFalse($container->has(JobHandlerInterface::class));
            self::assertInstanceOf($namespace.'\MailJob', $container->get($namespace.'\MailJobContract'));

            try {
                $container->get(JobHandlerInterface::class);
                self::fail('The shared handler interface must not be bound to a named job.');
            } catch (\RuntimeException) {
                self::assertFalse($container->has(JobHandlerInterface::class));
            }
            self::assertSame('job', $definitions[$namespace.'\MailJob']['scope']);
            self::assertSame($namespace.'\MailJob', $definitions[$namespace.'\MailJob']['provides']);
        } finally {
            unlink($path);
        }
    }

    public function test_compiled_job_scope_is_cached_until_reset_and_runs_job_hooks(): void
    {
        $compiler = new ContainerCompiler;
        $class = CompiledLifecycleJob::class;
        $namespace = 'TuskCompiledJob'.bin2hex(random_bytes(4));
        $code = $compiler->compile([$class => [
            'class' => $class,
            'provides' => $class,
            'scope' => 'job',
            'dependencies' => [],
            'interfaces' => [],
            'hooks' => ['job.start' => ['start'], 'job.end' => ['end']],
        ]], $namespace, 'Container');
        eval(substr($code, 5));
        $containerClass = $namespace.'\Container';
        $container = new $containerClass;

        $container->runLifecycleHooks('job.start');
        $first = $container->get($class);
        self::assertSame($first, $container->get($class));
        $container->runLifecycleHooks('job.end');
        self::assertSame(['start', 'end'], $first->events);

        $container->resetScope('job');
        $container->runLifecycleHooks('job.start');
        self::assertNotSame($first, $container->get($class));
        self::assertSame(['start'], $container->get($class)->events);
    }

    public function test_compiles_container_class_string(): void
    {
        $compiler = new ContainerCompiler;

        $definitions = [
            'App\Services\TestService' => [
                'class' => 'App\Services\TestService',
                'interfaces' => ['App\Contracts\TestServiceInterface'],
                'scope' => 'singleton',
                'dependencies' => [],
            ],
        ];

        $code = $compiler->compile($definitions, 'Tusk\TestCompiled', 'TestCompiledContainer');

        $this->assertStringContainsString('namespace Tusk\TestCompiled;', $code);
        $this->assertStringContainsString('class TestCompiledContainer implements TuskContainerInterface, ContainerInterface', $code);
        $this->assertStringContainsString('private function resolve_App_Services_TestService(): object', $code);
        self::assertStringContainsString('\'App\\\\Contracts\\\\TestServiceInterface\' => $this->get(\'App\\\\Services\\\\TestService\')', $code);
    }

    public function test_compiles_lifecycle_hooks_as_direct_calls(): void
    {
        $compiler = new ContainerCompiler;

        $code = $compiler->compile([
            'App\\Services\\LifecycleService' => [
                'class' => 'App\\Services\\LifecycleService',
                'provides' => 'App\\Services\\LifecycleService',
                'is_factory' => false,
                'scope' => 'worker',
                'dependencies' => [],
                'interfaces' => [],
                'hooks' => [
                    'worker.start' => ['start'],
                    'worker.stop' => ['stop'],
                ],
            ],
        ], 'Tusk\\TestCompiled', 'TestCompiledContainer');

        self::assertStringContainsString('private array $workerInstances = [];', $code);
        self::assertStringContainsString('public function runLifecycleHooks(string $event): void', $code);
        self::assertStringContainsString('$this->resolve_App_Services_LifecycleService()->start();', $code);
        self::assertStringContainsString('$this->resolve_App_Services_LifecycleService()->stop();', $code);
        self::assertStringNotContainsString('new ReflectionClass', $code);
    }

    public function test_compiles_nullable_dependencies_as_optional_resolutions(): void
    {
        $compiler = new ContainerCompiler;

        $code = $compiler->compile([
            'App\\Services\\OptionalService' => [
                'class' => 'App\\Services\\OptionalService',
                'provides' => 'App\\Services\\OptionalService',
                'is_factory' => false,
                'scope' => 'singleton',
                'dependencies' => ['App\\Contracts\\OptionalDependency'],
                'optional_dependencies' => ['App\\Contracts\\OptionalDependency'],
                'interfaces' => [],
                'hooks' => [],
            ],
        ], 'Tusk\\TestCompiled', 'TestCompiledContainer');

        self::assertStringContainsString(
            "(\$this->has('App\\\\Contracts\\\\OptionalDependency') ? \$this->get('App\\\\Contracts\\\\OptionalDependency') : null)",
            $code,
        );
    }
}
