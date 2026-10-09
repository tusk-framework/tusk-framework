<?php

namespace Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tusk\Foundation\Application;

final class AttributeControllerBootIntegrationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/tusk-controller-boot-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/config', 0777, true);
        mkdir($this->basePath.'/routes', 0777, true);
        mkdir($this->basePath.'/bootstrap', 0777, true);
        mkdir($this->basePath.'/app/Controller', 0777, true);
        file_put_contents($this->basePath.'/app/Controller/OrderController.php', <<<'PHP'
<?php
namespace TuskControllerBootFixture;
final readonly class OrderInput { public function __construct(public string $sku) {} }
#[\Tusk\Web\Attribute\Controller('/api/orders')]
final class OrderController
{
    #[\Tusk\Web\Attribute\Post('/{orderId}')]
    public function create(int $orderId, OrderInput $input): array { return ['id' => $orderId, 'sku' => $input->sku]; }
    #[\Tusk\Web\Attribute\Get('/psr')]
    public function psr(): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(202, ['X-Controller' => 'psr'], 'accepted'); }
}
PHP);
        require $this->basePath.'/app/Controller/OrderController.php';
        file_put_contents($this->basePath.'/routes/web.php', <<<'PHP'
<?php
return static function (\Tusk\Web\Router\Router $router): void {
    $router->addRoute(['GET'], '/explicit', [\Tests\Integration\ExplicitBootController::class, 'show']);
};
PHP);
        file_put_contents($this->basePath.'/bootstrap/providers.php', <<<'PHP'
<?php
return static function (\Tusk\Core\Container\Container $container): void {
    $container->instance(\Tests\Integration\ExplicitBootController::class, new \Tests\Integration\ExplicitBootController());
};
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function test_boot_discovers_controller_with_dto_and_psr_response_support(): void
    {
        $application = Application::configure($this->basePath)->withRouting(web: 'routes/web.php')->withProviders(['bootstrap/providers.php'])->create();

        $response = $application->handle((new ServerRequest('POST', '/api/orders/42'))->withParsedBody(['sku' => 'book']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 42, 'sku' => 'book'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));

        $psrResponse = $application->handle(new ServerRequest('GET', '/api/orders/psr'));
        self::assertSame(202, $psrResponse->getStatusCode());
        self::assertSame('psr', $psrResponse->getHeaderLine('X-Controller'));
        self::assertSame('accepted', (string) $psrResponse->getBody());

        self::assertSame('explicit route', (string) $application->handle(new ServerRequest('GET', '/explicit'))->getBody());
        self::assertSame(404, $application->handle(new ServerRequest('GET', '/missing'))->getStatusCode());
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}

final class ExplicitBootController
{
    public function show(): string
    {
        return 'explicit route';
    }
}
