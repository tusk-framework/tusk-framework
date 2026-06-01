<?php

namespace Tusk\Data\Bridge\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Tusk\Config\Env;
use Tusk\Contracts\Attributes\Factory;

#[Factory(provides: EntityManagerInterface::class, scope: 'singleton')]
class EntityManagerFactory
{
    /**
     * @return EntityManagerInterface
     */
    public function __invoke(): EntityManagerInterface
    {
        $paths = array_filter(explode(',', Env::get('DB_ENTITY_PATHS', getcwd() . '/src/Domain')));
        $isDevMode = Env::get('APP_ENV', 'development') !== 'production';

        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

        $dbParams = [
            'driver'   => Env::get('DB_DRIVER', 'pdo_sqlite'),
            'host'     => Env::get('DB_HOST', '127.0.0.1'),
            'port'     => Env::get('DB_PORT', 3306),
            'user'     => Env::get('DB_USER', 'root'),
            'password' => Env::get('DB_PASSWORD', ''),
            'dbname'   => Env::get('DB_NAME', 'tusk'),
        ];

        // For sqlite, support absolute/relative path
        if ($dbParams['driver'] === 'pdo_sqlite') {
            $dbParams['path'] = Env::get('DB_PATH', getcwd() . '/database/database.sqlite');
        }

        $connection = DriverManager::getConnection($dbParams, $config);

        return new EntityManager($connection, $config);
    }
}
