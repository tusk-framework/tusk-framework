<?php

namespace Tusk\Web\Tests\Router;

use PHPUnit\Framework\TestCase;
use Tusk\Web\Router\RouteCompiler;

final class RouteCompilerTest extends TestCase
{
    public function test_ide_metadata_files_are_not_loaded_as_routes(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-routes-'.bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);

        $metadata = $directory.DIRECTORY_SEPARATOR.'.phpstorm.meta.php';
        file_put_contents($metadata, <<<'PHP'
<?php

namespace Tusk\Web\Tests\Fixtures;

use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Get;

#[Controller]
final class IdeMetadataController
{
    #[Get('/ide-metadata')]
    public function index(): string
    {
        return 'metadata';
    }
}
PHP);

        try {
            $routes = (new RouteCompiler())->scan([$directory]);

            self::assertSame([], $routes['GET']);
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }
}
