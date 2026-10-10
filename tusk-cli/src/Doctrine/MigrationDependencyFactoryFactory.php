<?php

declare(strict_types=1);

namespace Tusk\Cli\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\EntityManager\EntityManagerLoader;
use Doctrine\Migrations\Configuration\Exception\InvalidLoader;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;

final class MigrationDependencyFactoryFactory
{
    private ?LazyEntityManagerLoader $entityManagerLoader = null;

    public function __construct(private readonly MigrationConfiguration $configuration) {}

    public function create(): DependencyFactory
    {
        $migrationConfig = new ConfigurationArray($this->configuration->values());

        $this->entityManagerLoader = new LazyEntityManagerLoader($this->configuration);

        return DependencyFactory::fromEntityManager(
            $migrationConfig,
            $this->entityManagerLoader,
        );
    }

    public function close(): void
    {
        $this->entityManagerLoader?->close();
    }
}

/** @internal Defers ORM and DBAL construction until a migration operation requests its EntityManager. */
final class LazyEntityManagerLoader implements EntityManagerLoader
{
    private ?EntityManagerInterface $entityManager = null;

    public function __construct(private readonly MigrationConfiguration $configuration) {}

    public function getEntityManager(?string $name = null): EntityManagerInterface
    {
        if ($name !== null) {
            throw InvalidLoader::noMultipleEntityManagers($this);
        }

        if ($this->entityManager === null) {
            $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
                $this->configuration->entityPaths(),
                $this->configuration->isDevelopmentMode(),
            );
            $connection = DriverManager::getConnection($this->configuration->connectionParameters(), $ormConfig);
            $this->entityManager = new EntityManager($connection, $ormConfig);
        }

        return $this->entityManager;
    }

    public function close(): void
    {
        $this->entityManager?->getConnection()->close();
    }
}
