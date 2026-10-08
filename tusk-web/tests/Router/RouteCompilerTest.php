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
            $routes = (new RouteCompiler)->scan([$directory]);

            self::assertSame([], $routes['GET']);
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }

    public function test_dependency_vendor_directories_are_not_scanned_as_routes(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-routes-'.bin2hex(random_bytes(4));
        $vendor = $directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'fixture';
        mkdir($vendor, 0755, true);

        $source = $vendor.DIRECTORY_SEPARATOR.'VendorController.php';
        file_put_contents($source, <<<'PHP'
<?php

namespace Tusk\Web\Tests\Fixtures\Vendor;

use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Get;

#[Controller]
final class VendorController
{
    #[Get('/vendor')]
    public function index(): string
    {
        return 'vendor';
    }
}
PHP);

        try {
            $routes = (new RouteCompiler)->scan([$directory]);

            self::assertSame([], $routes['GET']);
        } finally {
            unlink($source);
            rmdir($vendor);
            rmdir(dirname($vendor));
            rmdir($directory);
        }
    }

    public function test_local_git_worktree_directories_are_not_scanned_as_routes(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-routes-'.bin2hex(random_bytes(4));
        $worktree = $directory.DIRECTORY_SEPARATOR.'.worktrees'.DIRECTORY_SEPARATOR.'feature';
        mkdir($worktree, 0755, true);

        $namespace = 'TuskWebRouteWorktree'.bin2hex(random_bytes(4));
        $source = $worktree.DIRECTORY_SEPARATOR.'WorktreeController.php';
        file_put_contents($source, sprintf(<<<'PHP'
<?php

namespace %s;

use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Route;

#[Controller]
final class WorktreeController
{
    #[Route('/from-worktree')]
    public function index(): string
    {
        return 'worktree';
    }
}
PHP, $namespace));

        try {
            $routes = (new RouteCompiler)->scan([$directory]);

            self::assertSame([], $routes['GET']);
        } finally {
            unlink($source);
            rmdir($worktree);
            rmdir(dirname($worktree));
            rmdir($directory);
        }
    }
}
