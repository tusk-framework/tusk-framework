<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationRollbackCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setName('migrate:rollback')->setDescription('Roll back migrations to an explicit version')
            ->addArgument('target', InputArgument::REQUIRED, 'Target migration version, or 0 to roll back all')
            ->addOption('allow-down', null, InputOption::VALUE_NONE, 'Acknowledge potentially destructive down migrations')
            ->addOption('allow-production', null, InputOption::VALUE_NONE, 'Acknowledge rollback execution in production')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show SQL without applying rollback')
            ->addOption('write-sql', null, InputOption::VALUE_OPTIONAL, 'Write generated SQL to a file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getArgument('target');
        if ($target !== '0' && preg_match('/^[A-Za-z0-9_\\\\-]+$/', $target) !== 1) {
            $output->writeln('<error>Target must be a migration version or 0.</error>');

            return self::FAILURE;
        }
        if (! $input->getOption('allow-down')) {
            $output->writeln('<error>Rollback requires explicit acknowledgement with --allow-down.</error>');

            return self::FAILURE;
        }
        if ($this->isProduction() && ! $input->getOption('allow-production')) {
            $output->writeln('<error>Refusing to roll back migrations in production without --allow-production.</error>');

            return self::FAILURE;
        }

        return $this->safeExecute($input, $output, 'Migration rollback', function () use ($input, $output, $target): int {
            $projectFactory = $this->projectFactory();
            $configuration = $projectFactory->configuration();
            if ($configuration->sqlitePath() !== null && ! is_file($configuration->sqlitePath())) {
                $output->writeln('<error>Cannot roll back because the database has no migration history.</error>');

                return self::FAILURE;
            }
            $factory = $projectFactory->create();
            if ($input->getOption('dry-run') || $input->getOption('write-sql') !== null) {
                return $this->previewMigrations($factory, $target, $input->getOption('write-sql') ?: null, $output, 'down');
            }
            $command = new DoctrineMigrateCommand($factory);
            $command->setName('doctrine:migrate:internal');
            $arguments = ['version' => $target, '--allow-no-migration' => true];
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
