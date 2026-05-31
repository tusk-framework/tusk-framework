<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Cli\Generator\StubGenerator;
use Tusk\Contracts\Attributes\Service;

#[Service]
#[AsCommand('make:controller', 'Create a new HTTP Controller class')]
class MakeControllerCommand extends \Symfony\Component\Console\Command\Command
{
    protected function configure(): void
    {
        $this->setName('make:controller')
             ->setDescription('Create a new HTTP Controller class')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Http\\Controllers'; // Defaulting for simple usage
        
        if (str_contains($name, '\\')) {
            $namespace .= '\\'.dirname(str_replace('/', '\\', $name));
        }

        $stub = __DIR__.'/../../stubs/controller.stub';
        $target = getcwd().'/src/Http/Controllers/'.$name.'.php';

        $generator = new StubGenerator;

        // Let's create a temporary stub if it doesn't exist
        if (! file_exists($stub)) {
            $dir = dirname($stub);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($stub, "<?php\n\nnamespace {{ namespace }};\n\nuse Psr\Http\Message\ResponseInterface;\nuse Psr\Http\Message\ServerRequestInterface;\nuse Nyholm\Psr7\Response;\n\nclass {{ class }}\n{\n    public function index(ServerRequestInterface \$request): ResponseInterface\n    {\n        return new Response(200, [], 'Hello from {{ class }}');\n    }\n}\n");
        }

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
        ]);

        if ($success) {
            $output->writeln("<info>Controller created successfully at {$target}</info>");
            return self::SUCCESS;
        } else {
            $output->writeln("<error>Error: Could not create controller. File may already exist.</error>");
            return self::FAILURE;
        }
    }
}
