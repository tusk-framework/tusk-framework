<?php

namespace Tusk\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;

#[Service]
#[AsCommand('migrate', 'Run the database migrations')]
class MigrateCommand extends Command
{
    public function __construct(
        private ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('migrate')
             ->setDescription('Run the database migrations using Doctrine SchemaTool');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Updating database schema...</info>');

        try {
            /** @var \Doctrine\ORM\EntityManagerInterface $entityManager */
            $entityManager = $this->container->get(\Doctrine\ORM\EntityManagerInterface::class);
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($entityManager);
            $classes = $entityManager->getMetadataFactory()->getAllMetadata();

            if (empty($classes)) {
                $output->writeln('<comment>No entity classes found to migrate.</comment>');
                return self::SUCCESS;
            }

            $schemaTool->updateSchema($classes);

            $output->writeln('<info>Schema updated successfully!</info>');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
