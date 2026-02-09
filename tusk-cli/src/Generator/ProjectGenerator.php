<?php

namespace Tusk\Cli\Generator;

class ProjectGenerator
{
    public function generate(string $name, string $type): void
    {
        $baseDir = getcwd() . '/' . $name;

        if (is_dir($baseDir)) {
            throw new \RuntimeException("Directory '$name' already exists!");
        }

        mkdir($baseDir, 0755, true);
        mkdir("$baseDir/src/Controller", 0755, true);
        mkdir("$baseDir/public", 0755, true);
        mkdir("$baseDir/config", 0755, true);

        // 1. composer.json
        file_put_contents("$baseDir/composer.json", $this->getComposerJson($name, $type));

        // 2. docker-compose.yml
        file_put_contents("$baseDir/docker-compose.yml", $this->getDockerCompose($name, $type));

        // 3. .env
        file_put_contents("$baseDir/.env", "APP_ENV=dev\nDB_CONNECTION=mysql://user:secret@db:3306/app\n");

        // 4. public/index.php
        file_put_contents("$baseDir/public/index.php", $this->getIndexPhp());

        // 5. src/Controller/HomeController.php
        file_put_contents("$baseDir/src/Controller/HomeController.php", $this->getHomeController());

        // 6. Copy framework files for local dev (Since we are in monorepo)
        // In real world, `composer install` handles this. 
        // For verify_gen.php, we might need to manually link or just assume composer install works if internet is available.
    }

    private function getComposerJson(string $name, string $type): string
    {
        return json_encode([
            "name" => "app/$name",
            "type" => "project",
            "require" => [
                "php" => "^8.2",
                "tusk/framework" => "dev-main" // Assuming dev-main for now
            ],
            "autoload" => [
                "psr-4" => [
                    "App\\" => "src/"
                ]
            ],
            "repositories" => [
                [
                    "type" => "path",
                    "url" => "../tusk-framework" // HACK: for local dev verification
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function getDockerCompose(string $name, string $type): string
    {
        return <<<YAML
services:
  app:
    image: php:8.2-cli
    volumes:
      - ./:/app
    working_dir: /app
    command: php -S 0.0.0.0:8000 -t public
    ports:
      - "8000:8000"
    depends_on:
      - db

  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: app
YAML;
    }

    private function getIndexPhp(): string
    {
        return <<<'PHP'
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Tusk\Runtime\Kernel;
use Tusk\Core\Container\Container;
use Tusk\Web\HttpKernel;
use Tusk\Web\Router\Router;
use Tusk\Web\Http\Request;

// Bootstrap
$container = new Container();
$router = new Router();

// Register Controllers
$router->registerControllers([
    \App\Controller\HomeController::class
]);

$kernel = new HttpKernel($container, $router);

// Handle Request
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
PHP;
    }

    private function getHomeController(): string
    {
        return <<<'PHP'
<?php

namespace App\Controller;

use Tusk\Web\Attribute\Route;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;

class HomeController
{
    #[Route('/', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'Welcome to Tusk!',
            'version' => '0.7.0'
        ]));
    }
}
PHP;
    }
}
