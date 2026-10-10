<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setName('migrate')->setDescription('Apply pending versioned database migrations')
            ->addOption('allow-production', null, InputOption::VALUE_NONE, 'Acknowledge migration execution in production')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview pending migrations without applying them')
            ->addOption('write-sql', null, InputOption::VALUE_OPTIONAL, 'Write generated SQL to a file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isProduction() && ! $input->getOption('allow-production')) {
            $output->writeln('<error>Refusing to run migrations in production without --allow-production.</error>');

            return self::FAILURE;
        }

        return $this->safeExecute($input, $output, 'Migration', function () use ($input, $output): int {
            $configuration = $this->projectFactory()->configuration();
            if (($input->getOption('dry-run') || $input->getOption('write-sql') !== null)
                && $configuration->sqlitePath() !== null
                && ! is_file($configuration->sqlitePath())) {
                $output->writeln('<comment>No database file exists; there is nothing to preview.</comment>');

                return self::SUCCESS;
            }
            $projectFactory = $this->projectFactory();
            $factory = $projectFactory->create();
            if (count($factory->getMigrationRepository()->getMigrations()) === 0) {
                $output->writeln('<info>No migrations are registered.</info>');

                return self::SUCCESS;
            }
            if ($input->getOption('dry-run') || $input->getOption('write-sql') !== null) {
                return $this->previewMigrations($factory, 'latest', $input->getOption('write-sql') ?: null, $output);
            }
            $command = new DoctrineMigrateCommand($factory);
            $command->setName('doctrine:migrate:internal');
            $arguments = ['version' => 'latest', '--allow-no-migration' => true];
            foreach (['dry-run', 'write-sql'] as $option) {
                $value = $input->getOption($option);
                if ($value !== false && $value !== null) {
                    $arguments['--'.$option] = $value === true ? true : $value;
                }
            }
            $doctrineInput = new ArrayInput($arguments, $command->getDefinition());
            $doctrineInput->setInteractive(false);

            return $command->run($doctrineInput, $output);
        });
    }
}
