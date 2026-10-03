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
use Tusk\Runtime\RuntimeConfiguration;
use Tusk\Runtime\RuntimeModuleFactory;

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
        $requestedRuntime = $input->getOption('runtime');

        $outputBufferLevel = ob_get_level();
        ob_start();

        try {
            $bootstrap = require_once $filePath;
        } finally {
            $bootstrapOutput = '';
            while (ob_get_level() > $outputBufferLevel) {
                $buffer = ob_get_clean();
                if ($buffer !== false) {
                    $bootstrapOutput = $buffer.$bootstrapOutput;
                }
            }
        }

        $configuration = RuntimeConfiguration::fromArray(is_array($bootstrap) ? $bootstrap : []);
        $runtime = $requestedRuntime ?? $configuration->adapter();
        $adapter = RuntimeAdapterFactory::create($runtime);

        if ($adapter->getName() === 'roadrunner') {
            fwrite(STDERR, "Tusk Framework v0.1.0\nStarting application: {$file}\nRuntime: roadrunner\n");
            if ($bootstrapOutput !== '') {
                fwrite(STDERR, $bootstrapOutput);
            }
        } else {
            $output->writeln('<info>Tusk Framework v0.1.0</info>');
            $output->writeln("Starting application: {$file}");
            $output->writeln("Runtime: {$adapter->getName()}");
            if ($bootstrapOutput !== '') {
                $output->write($bootstrapOutput);
            }
        }

        $modules = RuntimeModuleFactory::fromConfiguration($configuration);
        $modules->register($container);

        $kernel = new Kernel($container, $adapter, null, $modules);

        $kernel->start();

        return self::SUCCESS;
    }
}
