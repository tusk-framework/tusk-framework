<?php

namespace Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tusk\Core\Container\ContainerCompiler;
use Tusk\Core\Container\ServiceScanner;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\CompiledRouter;
use Tusk\Web\Router\RouteCompiler;

class WebPipelineIntegrationTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/tusk_test_' . uniqid();
        mkdir($this->storageDir, 0777, true);
        
        // Emulate the compilation step (like `tusk build`)
        $this->compileApp();
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
    }

    private function deleteDir(string $dirPath): void
    {
        if (! is_dir($dirPath)) {
            return;
        }
        $files = array_diff(scandir($dirPath), ['.','..']);
        foreach ($files as $file) {
            (is_dir("$dirPath/$file")) ? $this->deleteDir("$dirPath/$file") : unlink("$dirPath/$file");
        }
        rmdir($dirPath);
    }

    private string $containerClass;
    private string $routerClass;

    private function compileApp(): void
    {
        $uid = uniqid();
        $this->containerClass = 'TestContainer_' . $uid;
        $this->routerClass = 'TestRouter_' . $uid;

        // Create a dummy Controller for integration testing
        eval('
            namespace Tests\Integration\Mocks;
            class DummyController_' . $uid . ' {
                public function hello() {
                    return "Hello World Integration";
                }
            }
        ');

        $controllerClass = 'Tests\Integration\Mocks\DummyController_' . $uid;

        // 1. Compile Container with the dummy controller
        $compiler = new ContainerCompiler();
        $definitions = [
            $controllerClass => [
                'class' => $controllerClass,
                'interfaces' => [],
                'scope' => 'singleton',
                'dependencies' => []
            ]
        ];
        $code = $compiler->compile($definitions, 'Tusk\Compiled', $this->containerClass);
        file_put_contents($this->storageDir . '/' . $this->containerClass . '.php', $code);

        // 2. Compile Routes
        $routesCode = <<<PHP
<?php
namespace Tusk\Compiled;
class {$this->routerClass} implements \Tusk\Web\Router\RouterInterface {
    public function match(string \$method, string \$uri): ?\Tusk\Web\Router\RouteMatch {
        if (\$method === 'GET' && \$uri === '/hello') {
            return new \Tusk\Web\Router\RouteMatch('$controllerClass', 'hello');
        }
        return null;
    }
}
PHP;
        file_put_contents($this->storageDir . '/' . $this->routerClass . '.php', $routesCode);
        
        require_once $this->storageDir . '/' . $this->containerClass . '.php';
        require_once $this->storageDir . '/' . $this->routerClass . '.php';
    }

    public function test_full_web_request_cycle(): void
    {
        $containerClass = '\\Tusk\\Compiled\\' . $this->containerClass;
        $routerClass = '\\Tusk\\Compiled\\' . $this->routerClass;
        
        $container = new $containerClass();
        $router = new $routerClass();
        
        $kernel = new HttpKernel($container, $router);
        
        $request = new ServerRequest('GET', '/hello');
        
        $response = $kernel->handle($request);
        
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World Integration', (string) $response->getBody());
    }

    public function test_404_not_found(): void
    {
        $containerClass = '\\Tusk\\Compiled\\' . $this->containerClass;
        $routerClass = '\\Tusk\\Compiled\\' . $this->routerClass;
        
        $container = new $containerClass();
        $router = new $routerClass();
        
        $kernel = new HttpKernel($container, $router);
        
        $request = new ServerRequest('GET', '/not-found');
        
        $response = $kernel->handle($request);
        
        $this->assertEquals(404, $response->getStatusCode());
    }
}
