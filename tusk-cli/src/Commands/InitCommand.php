<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Cli\Generator\ProjectGenerator;
use Tusk\Contracts\Attributes\Service;

#[Service]
#[AsCommand('init', 'Initialize a new Tusk project')]
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')
             ->setDescription('Initialize a new Tusk project')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the project')
             ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'The project type (api or micro)', 'api');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $type = $input->getOption('type');

        $output->writeln("<info>Creating Tusk Project: {$name}</info>");
        $output->writeln("Type: {$type}");

        try {
            $generator = new ProjectGenerator;
            $generator->generate($name, $type);

            $output->writeln("<info>Project '{$name}' created successfully!</info>");
            $output->writeln("1. cd {$name}");
            $output->writeln("2. composer install");
            $output->writeln("3. docker-compose up");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");

            return self::FAILURE;
        }
    }
}
