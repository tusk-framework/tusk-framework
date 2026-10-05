<?php

namespace Tusk\Cli\Generator;

class ProjectGenerator
{
    public function generate(string $name, string $type): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException('Project name must be a single directory name.');
        }

        $baseDir = getcwd().'/'.$name;
        if (file_exists($baseDir)) {
            throw new \RuntimeException("Directory '$name' already exists!");
        }

        foreach (['app/Controller', 'bootstrap', 'config', 'routes', 'public'] as $directory) {
            mkdir($baseDir.'/'.$directory, 0755, true);
        }

        foreach ([
            'bootstrap-app.stub' => 'bootstrap/app.php',
            'public-index.stub' => 'public/index.php',
            'routes-web.stub' => 'routes/web.php',
            'config-app.stub' => 'config/app.php',
            'gitignore.stub' => '.gitignore',
        ] as $stub => $destination) {
            file_put_contents($baseDir.'/'.$destination, file_get_contents(__DIR__.'/../../stubs/'.$stub));
        }

        file_put_contents($baseDir.'/app/Controller/HomeController.php', $this->getHomeController());
        file_put_contents($baseDir.'/bootstrap/providers.php', <<<'PHP'
<?php

use App\Controller\HomeController;
use Tusk\Core\Container\Container;

return static function (Container $container): void {
    $container->register(HomeController::class);
};
PHP);
        file_put_contents($baseDir.'/composer.json', $this->getComposerJson($name));
        file_put_contents($baseDir.'/tusk.json', json_encode([
            'port' => 8080,
            'worker_count' => 4,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function getComposerJson(string $name): string
    {
        return json_encode([
            'name' => "app/$name",
            'type' => 'project',
            'require' => [
                'php' => '^8.2',
                'tusk/framework' => 'dev-main',
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function getHomeController(): string
    {
        return <<<'PHP'
<?php

namespace App\Controller;

use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Tusk\Contracts\Attributes\Service;

#[Service]
class HomeController
{
    public function index(Request $request): ResponseInterface
    {
        return Response::html('Welcome to Tusk!');
    }
}
PHP;
    }
}
