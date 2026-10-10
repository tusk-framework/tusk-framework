<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Doctrine\MigrationConfiguration;

final class MigrationStatusCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setName('migrate:status')->setDescription('Show applied and pending migration versions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->safeExecute($input, $output, 'Migration status', function () use ($output): int {
            $configuration = MigrationConfiguration::load($this->projectRoot);
            $sqlitePath = $configuration->sqlitePath();
            $factory = $this->projectFactory()->create();
            $available = $factory->getMigrationRepository()->getMigrations();
            if ($sqlitePath !== null && ! is_file($sqlitePath)) {
                $this->writePendingMigrations($available->getItems(), $output);
                $output->writeln('<comment>Migration history is not initialized; database file does not exist.</comment>');

                return self::SUCCESS;
            }
            $connection = $factory->getConnection();
            $storage = $factory->getMetadataStorage();
            $table = $factory->getConfiguration()->getMetadataStorageConfiguration();
            $tableName = $table instanceof TableMetadataStorageConfiguration ? $table->getTableName() : 'doctrine_migration_versions';

            if (! $connection->createSchemaManager()->tablesExist([$tableName])) {
                $this->writePendingMigrations($available->getItems(), $output);
                $output->writeln('<comment>Migration history is not initialized; metadata table does not exist.</comment>');

                return self::SUCCESS;
            }

            $executed = $storage->getExecutedMigrations();
            foreach ($available->getItems() as $migration) {
                $version = $migration->getVersion();
                $done = $executed->hasMigration($version);
                $output->writeln(sprintf('%s %s', $done ? '<info>applied</info>' : '<comment>pending</comment>', $version));
            }
            if (count($available) === 0) {
                $output->writeln('<info>No migrations are registered.</info>');
            }

            return self::SUCCESS;
        });
    }

    /** @param list<AvailableMigration> $migrations */
    private function writePendingMigrations(array $migrations, OutputInterface $output): void
    {
        foreach ($migrations as $migration) {
            $output->writeln(sprintf('<comment>pending</comment> %s', $migration->getVersion()));
        }
        if ($migrations === []) {
            $output->writeln('<info>No migrations are registered.</info>');
        }
    }
}
