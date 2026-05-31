<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Core\Container\Container;
use Tusk\Runtime\Kernel;

#[Service]
#[AsCommand('run', 'Run a Tusk application file directly')]
class RunCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('run')
             ->setDescription('Run a Tusk application file directly')
             ->addArgument('file', InputArgument::REQUIRED, 'The file to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $filePath = realpath($file);

        if (! $filePath || ! file_exists($filePath)) {
            $output->writeln("<error>File not found: {$file}</error>");

            return self::FAILURE;
        }

        $output->writeln("<info>Tusk Framework v0.1.0</info>");
        $output->writeln("Starting application: {$file}");

        $container = new Container;
        $kernel = new Kernel($container);

        require_once $filePath;

        $kernel->start();

        return self::SUCCESS;
    }
}
