<?php

namespace Tusk\Cli\Tests\Generator;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Commands\InitCommand;
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
        (new ProjectGenerator)->generate('sample', 'api');
        $root = $this->directory.'/sample';

        foreach (['app/Controller/HomeController.php', 'app/Jobs/WelcomeJob.php', 'app/Jobs/dispatch-example.php', 'bootstrap/app.php', 'bootstrap/providers.php', 'config/app.php', 'config/runtime.php', 'routes/web.php', 'public/index.php', '.gitignore', 'tusk.json', 'composer.json'] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
        self::assertStringContainsString('/.tusk/', file_get_contents($root.'/.gitignore'));
        self::assertDirectoryDoesNotExist($root.'/.tusk');
        self::assertFileDoesNotExist($root.'/.tusk/runtime/worker.php');
        self::assertSame(['port' => 8080, 'worker_count' => 4], json_decode(file_get_contents($root.'/tusk.json'), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('app/', json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR)['autoload']['psr-4']['App\\']);
        self::assertStringContainsString("->withJobs(__DIR__.'/../app/Jobs')", file_get_contents($root.'/bootstrap/app.php'));
        self::assertStringContainsString('capabilities.jobs', file_get_contents($root.'/config/runtime.php'));
        self::assertStringContainsString("AsJob('welcome.email')", file_get_contents($root.'/app/Jobs/WelcomeJob.php'));
        self::assertStringContainsString("json_encode(['user_id' => \$userId], JSON_THROW_ON_ERROR)", file_get_contents($root.'/app/Jobs/dispatch-example.php'));
        self::assertStringContainsString("'max_attempts' => 3", file_get_contents($root.'/config/runtime.php'));
        self::assertStringContainsString("'modules' => ['http', 'capabilities.jobs']", file_get_contents($root.'/config/runtime.php'));

        require $root.'/app/Controller/HomeController.php';
        require $root.'/app/Jobs/dispatch-example.php';
        $application = require $root.'/bootstrap/app.php';
        self::assertInstanceOf(Application::class, $application);
        self::assertSame(realpath($root), realpath($application->basePath()));
        self::assertIsArray(require $root.'/config/app.php');
        self::assertIsArray(require $root.'/config/runtime.php');
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
            self::assertStringNotContainsString('SwooleAdapter', $contents);
            self::assertStringNotContainsString('runWorker(', $contents);
        }
    }

    public function test_refuses_existing_project_without_changing_user_files(): void
    {
        mkdir($this->directory.'/sample/bootstrap', 0777, true);
        file_put_contents($this->directory.'/sample/bootstrap/app.php', 'user owned');
        file_put_contents($this->directory.'/sample/.gitignore', "user rules\n");

        try {
            (new ProjectGenerator)->generate('sample', 'api');
            self::fail('Expected existing directory to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame('user owned', file_get_contents($this->directory.'/sample/bootstrap/app.php'));
        self::assertSame("user rules\n", file_get_contents($this->directory.'/sample/.gitignore'));
        self::assertFileDoesNotExist($this->directory.'/sample/tusk.json');
    }

    public function test_run_command_directs_existing_application_to_engine(): void
    {
        file_put_contents($this->directory.'/app.php', '<?php file_put_contents(__DIR__."/executed", "yes");');
        $tester = new CommandTester(new RunCommand);

        self::assertSame(1, $tester->execute(['file' => $this->directory.'/app.php']));
        self::assertStringContainsString('tusk start', $tester->getDisplay());
        self::assertStringNotContainsString('tusk up', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/executed');
    }

    public function test_init_guidance_uses_engine_start_without_compose(): void
    {
        $tester = new CommandTester(new InitCommand);

        self::assertSame(0, $tester->execute(['name' => 'sample']));
        self::assertStringContainsString('tusk start', $tester->getDisplay());
        self::assertStringNotContainsString('docker-compose', $tester->getDisplay());
        self::assertStringNotContainsString('docker compose', $tester->getDisplay());
    }

    public function test_generated_public_entrypoint_preserves_php_request_state(): void
    {
        (new ProjectGenerator)->generate('sample', 'api');
        $root = $this->directory.'/sample';
        mkdir($root.'/vendor');
        file_put_contents($root.'/routes/web.php', <<<'PHP'
<?php

use App\Controller\RequestStateController;
use Tusk\Web\Router\Router;

return static function (Router $router): void {
    $router->addRoute(['POST'], '/', [RequestStateController::class, 'index']);
};
PHP);
        file_put_contents($root.'/bootstrap/providers.php', <<<'PHP'
<?php

use App\Controller\RequestStateController;
use Tusk\Core\Container\Container;

return static function (Container $container): void {
    $container->register(RequestStateController::class);
};
PHP);
        file_put_contents($root.'/vendor/autoload.php', '<?php require '.var_export($this->originalDirectory.'/vendor/autoload.php', true).'; require __DIR__."/../app/Controller/RequestStateController.php";');
        $uploadPath = $this->directory.'/nested-upload.txt';
        file_put_contents($uploadPath, 'nested upload');
        file_put_contents($root.'/app/Controller/RequestStateController.php', <<<'PHP'
<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Tusk\Contracts\Attributes\Service;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;

#[Service]
class RequestStateController
{
    public function index(Request $request): ResponseInterface
    {
        $psrRequest = $request->getPsrRequest();
        $uploads = $psrRequest->getUploadedFiles();
        $cover = $uploads['documents']['cover'] ?? null;
        $attachment = $uploads['documents']['attachments']['first'] ?? null;

        if (! $cover instanceof UploadedFileInterface || ! $attachment instanceof UploadedFileInterface) {
            return Response::json(['uploads_are_psr7' => false]);
        }

        return Response::json([
            'parsed' => $request->getParsedBody(),
            'cookies' => $psrRequest->getCookieParams(),
            'uploads_are_psr7' => true,
            'uploads' => [
                'cover' => [
                    'filename' => $cover->getClientFilename(),
                    'media_type' => $cover->getClientMediaType(),
                    'size' => $cover->getSize(),
                    'contents' => (string) $cover->getStream(),
                ],
                'attachment' => [
                    'filename' => $attachment->getClientFilename(),
                    'contents' => (string) $attachment->getStream(),
                ],
            ],
        ]);
    }
}
PHP);

        $previous = [$_SERVER, $_POST, $_COOKIE, $_FILES];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = ['title' => 'Hello'];
        $_COOKIE = ['session' => 'abc'];
        $_FILES = [
            'documents' => [
                'name' => ['cover' => 'cover.txt', 'attachments' => ['first' => 'first.txt']],
                'type' => ['cover' => 'text/plain', 'attachments' => ['first' => 'text/plain']],
                'tmp_name' => ['cover' => $uploadPath, 'attachments' => ['first' => $uploadPath]],
                'error' => ['cover' => UPLOAD_ERR_OK, 'attachments' => ['first' => UPLOAD_ERR_OK]],
                'size' => ['cover' => 13, 'attachments' => ['first' => 13]],
            ],
        ];

        try {
            ob_start();
            require $root.'/public/index.php';
            $payload = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            [$_SERVER, $_POST, $_COOKIE, $_FILES] = $previous;
        }

        self::assertSame(['title' => 'Hello'], $payload['parsed']);
        self::assertSame(['session' => 'abc'], $payload['cookies']);
        self::assertTrue($payload['uploads_are_psr7']);
        self::assertSame('cover.txt', $payload['uploads']['cover']['filename']);
        self::assertSame('text/plain', $payload['uploads']['cover']['media_type']);
        self::assertSame(13, $payload['uploads']['cover']['size']);
        self::assertSame('nested upload', $payload['uploads']['cover']['contents']);
        self::assertSame('first.txt', $payload['uploads']['attachment']['filename']);
        self::assertSame('nested upload', $payload['uploads']['attachment']['contents']);
    }

    public function test_rejects_path_traversal_project_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProjectGenerator)->generate('../outside', 'api');
    }
}
