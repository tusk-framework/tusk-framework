<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Cli\Generator\StubGenerator;
use Tusk\Contracts\Attributes\Service;

#[Service]
#[AsCommand('make:controller', 'Create a new HTTP Controller class')]
class MakeControllerCommand extends Command
{
    public function __construct(private readonly string $projectRoot = '')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('make:controller')
            ->setDescription('Create a new HTTP Controller class')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (! is_string($name) || ! $this->isSafeClassName($name)) {
            $output->writeln('<error>Provide a safe relative class name, optionally with namespace segments.</error>');

            return self::FAILURE;
        }

        $className = basename(str_replace('\\', '/', $name));
        $route = strtolower(preg_replace('/Controller$/', '', $className) ?: $className);
        $relativeNamespace = str_replace('/', '\\', dirname(str_replace('\\', '/', $name)));
        $namespace = 'App\\Controller'.($relativeNamespace === '.' ? '' : '\\'.$relativeNamespace);

        $stub = __DIR__.'/../../stubs/controller.stub';
        $root = $this->projectRoot !== '' ? $this->projectRoot : (getcwd() ?: '.');
        $target = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Controller'.DIRECTORY_SEPARATOR.str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name).'.php';

        $generator = new StubGenerator;

        if (! file_exists($stub)) {
            $output->writeln("<error>Error: Stub not found at {$stub}</error>");

            return self::FAILURE;
        }

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
            'route' => $route,
        ]);

        if ($success) {
            $output->writeln("<info>Controller created successfully at {$target}</info>");

            return self::SUCCESS;
        } else {
            $output->writeln('<error>Error: Could not create controller. File may already exist.</error>');

            return self::FAILURE;
        }
    }

    private function isSafeClassName(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        $segments = preg_split('~[\\\\/]~', $name);

        return $segments !== false && count(array_filter($segments, static fn (string $segment): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) === 1)) === count($segments);
    }
}
