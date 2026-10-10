<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SchemaSyncCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setName('schema:sync')->setDescription('Synchronize schema directly from ORM metadata (development only)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply schema changes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show SQL without applying changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isProduction()) {
            $output->writeln('<error>schema:sync is prohibited in production; use versioned migrations.</error>');

            return self::FAILURE;
        }
        if (! $input->getOption('force') && ! $input->getOption('dry-run')) {
            $output->writeln('<error>Schema synchronization requires --force or --dry-run.</error>');

            return self::FAILURE;
        }

        return $this->safeExecute($input, $output, 'Schema synchronization', function () use ($input, $output): int {
            $projectFactory = $this->projectFactory();
            $configuration = $projectFactory->configuration();
            $entityManager = $projectFactory->create()->getEntityManager();
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            if ($metadata === []) {
                $output->writeln('<comment>No entity metadata found.</comment>');

                return self::SUCCESS;
            }
            $schemaTool = new SchemaTool($entityManager);
            if ($input->getOption('dry-run')) {
                $sqlitePath = $configuration->sqlitePath();
                $sql = $sqlitePath !== null && ! is_file($sqlitePath)
                    ? $schemaTool->getCreateSchemaSql($metadata)
                    : $schemaTool->getUpdateSchemaSql($metadata);
                foreach ($sql as $statement) {
                    $output->writeln($statement.';');
                }
                $output->writeln('<comment>Dry run; schema was not changed.</comment>');

                return self::SUCCESS;
            }
            $schemaTool->updateSchema($metadata);
            $output->writeln('<info>Schema synchronized.</info>');

            return self::SUCCESS;
        });
    }
}
