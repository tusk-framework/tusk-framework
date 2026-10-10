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
    public function __construct(private readonly MigrationConfiguration $configuration) {}

    public function create(): DependencyFactory
    {
        $migrationConfig = new ConfigurationArray($this->configuration->values());

        return DependencyFactory::fromEntityManager(
            $migrationConfig,
            new LazyEntityManagerLoader($this->configuration),
        );
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
                (string) (getenv('APP_ENV') ?: 'development') !== 'production',
            );
            $connection = DriverManager::getConnection($this->configuration->connectionParameters(), $ormConfig);
            $this->entityManager = new EntityManager($connection, $ormConfig);
        }

        return $this->entityManager;
    }
}
