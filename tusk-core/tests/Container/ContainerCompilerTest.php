<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Container\ContainerCompiler;

class ContainerCompilerTest extends TestCase
{
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
