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
#[AsCommand('make:model', 'Create a new Domain Model class')]
class MakeModelCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('make:model')
             ->setDescription('Create a new Domain Model class')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the model class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = basename(str_replace('\\', '/', $name));
        $namespace = 'App\\Domain\\Models';
        
        if (str_contains($name, '\\')) {
            $namespace .= '\\'.dirname(str_replace('/', '\\', $name));
        }

        $stub = __DIR__.'/../../stubs/model.stub';
        $target = getcwd().'/src/Domain/Models/'.$name.'.php';

        $generator = new StubGenerator;

        if (! file_exists($stub)) {
            $dir = dirname($stub);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($stub, "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // Model implementation\n}\n");
        }

        $success = $generator->generate($stub, $target, [
            'namespace' => $namespace,
            'class' => $className,
        ]);

        if ($success) {
            $output->writeln("<info>Model created successfully at {$target}</info>");
            return self::SUCCESS;
        } else {
            $output->writeln("<error>Error: Could not create model. File may already exist.</error>");
            return self::FAILURE;
        }
    }
}
