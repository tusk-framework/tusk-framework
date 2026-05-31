<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Container\ContainerCompiler;

class ContainerCompilerTest extends TestCase
{
    public function test_compiles_container_class_string(): void
    {
        $compiler = new ContainerCompiler();
        
        $definitions = [
            'App\Services\TestService' => [
                'class' => 'App\Services\TestService',
                'interfaces' => ['App\Contracts\TestServiceInterface'],
                'scope' => 'singleton',
                'dependencies' => []
            ]
        ];

        $code = $compiler->compile($definitions, 'Tusk\TestCompiled', 'TestCompiledContainer');
        
        $this->assertStringContainsString('namespace Tusk\TestCompiled;', $code);
        $this->assertStringContainsString('class TestCompiledContainer implements TuskContainerInterface, ContainerInterface', $code);
        $this->assertStringContainsString('private function resolve_App_Services_TestService(): object', $code);
        $this->assertStringContainsString('\'App\Contracts\TestServiceInterface\' => $this->get(\'App\Services\TestService\')', $code);
    }
}
