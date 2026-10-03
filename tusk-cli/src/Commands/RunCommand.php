<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Kernel;
use Tusk\Runtime\RuntimeAdapterFactory;

#[Service]
#[AsCommand('run', 'Run a Tusk application file directly')]
class RunCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('run')
             ->setDescription('Run a Tusk application file directly')
             ->addArgument('file', InputArgument::REQUIRED, 'The file to run')
             ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'Runtime adapter: roadrunner or native');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $filePath = realpath($file);

        if (! $filePath || ! file_exists($filePath)) {
            $output->writeln("<error>File not found: {$file}</error>");

            return self::FAILURE;
        }

        $container = new Container;
        $adapter = RuntimeAdapterFactory::create($input->getOption('runtime'));

        if ($adapter->getName() === 'roadrunner') {
            fwrite(STDERR, "Tusk Framework v0.1.0\nStarting application: {$file}\nRuntime: roadrunner\n");
        } else {
            $output->writeln("<info>Tusk Framework v0.1.0</info>");
            $output->writeln("Starting application: {$file}");
            $output->writeln("Runtime: {$adapter->getName()}");
        }

        $outputBufferLevel = ob_get_level();
        if ($adapter->getName() === 'roadrunner') {
            ob_start();
        }

        try {
            require_once $filePath;
        } finally {
            if ($adapter->getName() === 'roadrunner' && ob_get_level() > $outputBufferLevel) {
                $bootstrapOutput = '';
                while (ob_get_level() > $outputBufferLevel) {
                    $buffer = ob_get_clean();
                    if ($buffer !== false) {
                        $bootstrapOutput = $buffer . $bootstrapOutput;
                    }
                }
                if ($bootstrapOutput !== '') {
                    fwrite(STDERR, $bootstrapOutput);
                }
            }
        }

        $kernel = new Kernel($container, $adapter);

        $kernel->start();

        return self::SUCCESS;
    }
}
