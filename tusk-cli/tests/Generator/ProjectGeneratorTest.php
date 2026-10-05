<?php

namespace Tusk\Cli\Tests\Generator;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Commands\RunCommand;
use Tusk\Cli\Generator\ProjectGenerator;
use Tusk\Config\Repository;
use Tusk\Foundation\Application;
use Tusk\Web\Router\Router;

class ProjectGeneratorTest extends TestCase
{
    private string $directory;
    private string $originalDirectory;

    protected function setUp(): void
    {
        $this->originalDirectory = getcwd();
        $this->directory = sys_get_temp_dir().'/tusk-generator-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_generates_bootstrappable_application_and_minimal_platform_config(): void
    {
        (new ProjectGenerator())->generate('sample', 'api');
        $root = $this->directory.'/sample';

        foreach (['app/Controller/HomeController.php', 'bootstrap/app.php', 'bootstrap/providers.php', 'config/app.php', 'routes/web.php', 'public/index.php', 'tusk.json', 'composer.json'] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
        self::assertDirectoryDoesNotExist($root.'/.tusk');
        self::assertFileDoesNotExist($root.'/.tusk/runtime/worker.php');
        self::assertSame(['port' => 8080, 'worker_count' => 4], json_decode(file_get_contents($root.'/tusk.json'), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('app/', json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR)['autoload']['psr-4']['App\\']);

        require $root.'/app/Controller/HomeController.php';
        $application = require $root.'/bootstrap/app.php';
        self::assertInstanceOf(Application::class, $application);
        self::assertSame(realpath($root), realpath($application->basePath()));
        self::assertIsArray(require $root.'/config/app.php');
        self::assertIsCallable(require $root.'/routes/web.php');
        self::assertInstanceOf(Router::class, $application->container()->get(Router::class));
        self::assertInstanceOf(Repository::class, $application->container()->get(Repository::class));
        $response = $application->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Welcome to Tusk', (string) $response->getBody());

        foreach (['bootstrap/app.php', 'routes/web.php', 'public/index.php', 'app/Controller/HomeController.php'] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            self::assertStringNotContainsString('NativeLoopAdapter', $contents);
            self::assertStringNotContainsString('NDJSON', $contents);
            self::assertStringNotContainsString('runWorker(', $contents);
        }
    }

    public function test_refuses_existing_project_without_changing_user_files(): void
    {
        mkdir($this->directory.'/sample/bootstrap', 0777, true);
        file_put_contents($this->directory.'/sample/bootstrap/app.php', 'user owned');

        try {
            (new ProjectGenerator())->generate('sample', 'api');
            self::fail('Expected existing directory to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame('user owned', file_get_contents($this->directory.'/sample/bootstrap/app.php'));
        self::assertFileDoesNotExist($this->directory.'/sample/tusk.json');
    }

    public function test_run_command_directs_existing_application_to_engine(): void
    {
        file_put_contents($this->directory.'/app.php', '<?php file_put_contents(__DIR__."/executed", "yes");');
        $tester = new CommandTester(new RunCommand());

        self::assertSame(1, $tester->execute(['file' => $this->directory.'/app.php']));
        self::assertStringContainsString('Engine', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/executed');
    }

    public function test_rejects_path_traversal_project_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectGenerator())->generate('../outside', 'api');
    }
}
