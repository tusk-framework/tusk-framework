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
    protected function configure(): void
    {
        $this->setName('make:entity')
             ->setDescription('Create a new Doctrine Entity class')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the entity class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Domain';
        
        if (str_contains($name, '\\')) {
            $namespace .= '\\'.dirname(str_replace('/', '\\', $name));
        }

        $stub = __DIR__.'/../../stubs/entity.stub';
        $target = getcwd().'/src/Domain/'.$name.'.php';

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
            $output->writeln("<comment>Remember to run 'php bin/tusk migrate' to update the schema.</comment>");
            return self::SUCCESS;
        } else {
            $output->writeln("<error>Error: Could not create entity. File may already exist.</error>");
            return self::FAILURE;
        }
    }
}
