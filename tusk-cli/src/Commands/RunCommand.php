<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;

#[Service]
#[AsCommand('run', 'Run scripts through the Tusk Engine')]
class RunCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('run')
            ->setDescription('Run scripts through the Tusk Engine')
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

        $output->writeln('<error>Application servers are managed by the Tusk Engine. Use `tusk up` from the project directory.</error>');

        return self::FAILURE;
    }
}
