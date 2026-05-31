<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Data\Migration\Migrator;

#[Service]
#[AsCommand('migrate', 'Run the database migrations')]
class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate')
             ->setDescription('Run the database migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrationsPath = getcwd().'/database/migrations';

        if (! is_dir($migrationsPath)) {
            $output->writeln("<error>Migrations directory not found at {$migrationsPath}</error>");

            return self::FAILURE;
        }

        $migrator = new Migrator($migrationsPath);

        try {
            $output->writeln("<info>Running migrations...</info>");
            $migrator->run();
            $output->writeln("<info>Migrations completed successfully.</info>");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Migration failed: " . $e->getMessage() . "</error>");

            return self::FAILURE;
        }
    }
}
