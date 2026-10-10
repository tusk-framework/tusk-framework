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
#[AsCommand('make:entity', 'Create a new Doctrine Entity class')]
class MakeEntityCommand extends Command
{
    public function __construct(private readonly string $projectRoot = '')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('make:entity')
             ->setDescription('Create a new Doctrine Entity class')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the entity class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (! is_string($name) || ! $this->isSafeClassName($name)) {
            $output->writeln('<error>Provide a safe relative class name, optionally with namespace segments.</error>');

            return self::FAILURE;
        }

        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Domain';

        $relativeNamespace = str_replace('/', '\\', dirname(str_replace('\\', '/', $name)));
        if ($relativeNamespace !== '.') {
            $namespace .= '\\'.$relativeNamespace;
        }

        $stub = __DIR__.'/../../stubs/entity.stub';
        $root = $this->projectRoot !== '' ? $this->projectRoot : (getcwd() ?: '.');
        $target = rtrim($root, '/\\').'/src/Domain/'.str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name).'.php';

        $generator = new StubGenerator;

        if (! file_exists($stub)) {
            $output->writeln("<error>Error: Stub not found at {$stub}</error>");
            return self::FAILURE;
        }

        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
            'table' => $table,
        ]);

        if ($success) {
            $output->writeln("<info>Entity created successfully at {$target}</info>");
            $output->writeln("<comment>Review the generated migration with 'php bin/tusk make:migration', then apply it with 'php bin/tusk migrate'.</comment>");
            return self::SUCCESS;
        } else {
            $output->writeln("<error>Error: Could not create entity. File may already exist.</error>");
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
