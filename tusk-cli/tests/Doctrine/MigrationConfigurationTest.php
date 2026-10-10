<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cli\Doctrine\MigrationConfiguration;
use Tusk\Cli\Doctrine\MigrationDependencyFactoryFactory;
use Tusk\Data\Bridge\Doctrine\EntityManagerFactory;

final class MigrationConfigurationTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_relative_migration_paths_resolve_from_project_root_after_changing_working_directory(): void
    {
        $root = $this->projectRoot();
        mkdir($root.'/database/migrations', 0777, true);
        file_put_contents($root.'/config/migrations.php', "<?php return ['migrations_paths' => ['App\\\\Migrations' => 'database/migrations']];");
        $elsewhere = $this->temporaryDirectory();
        $originalDirectory = getcwd();

        try {
            self::assertNotSame(false, chdir($elsewhere));
            $configuration = MigrationConfiguration::load($root);
        } finally {
            if ($originalDirectory !== false) {
                chdir($originalDirectory);
            }
        }

        self::assertSame($this->normalizePath($root.'/database/migrations'), $this->normalizePath($configuration->migrationsPaths()['App\\Migrations']));
    }

    public function test_absent_configuration_uses_generated_project_defaults(): void
    {
        $root = $this->projectRoot();
        $configuration = MigrationConfiguration::load($root);

        self::assertSame(['App\\Migrations' => $this->normalizePath($root.'/database/migrations')], $this->normalizePaths($configuration->migrationsPaths()));
        self::assertSame('doctrine_migration_versions', $configuration->tableName());
        self::assertSame('version', $configuration->versionColumnName());
        self::assertSame('executed_at', $configuration->executedAtColumnName());
        self::assertSame('execution_time', $configuration->executionTimeColumnName());
    }

    public function test_malformed_configuration_returns_actionable_failure_without_leaking_source_or_runtime_details(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root.'/config/migrations.php', "<?php throw new \\RuntimeException('secret-driver-detail');");

        try {
            MigrationConfiguration::load($root);
            self::fail('Malformed migration configuration should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('config/migrations.php', $exception->getMessage());
            self::assertStringContainsString('check', strtolower($exception->getMessage()));
            self::assertStringNotContainsString('secret-driver-detail', $exception->getMessage());
        }
    }

    public function test_configuration_and_factory_construction_do_not_connect_or_create_sqlite_file(): void
    {
        $root = $this->projectRoot();
        $this->withEnvironment([
            'DB_DRIVER' => 'pdo_sqlite',
            'DB_PATH' => $root.'/database/database.sqlite',
            'DB_ENTITY_PATHS' => $root.'/app',
        ], function () use ($root): void {
            $configuration = MigrationConfiguration::load($root);
            $factory = new MigrationDependencyFactoryFactory($configuration);
            self::assertInstanceOf(MigrationDependencyFactoryFactory::class, $factory);
            self::assertFileDoesNotExist($root.'/database/database.sqlite');
        });
    }

    public function test_factory_creates_dependency_factory_without_connecting_until_connection_is_requested(): void
    {
        $root = $this->projectRoot();
        mkdir($root.'/app', 0777, true);
        $this->withEnvironment([
            'DB_DRIVER' => 'pdo_sqlite',
            'DB_PATH' => $root.'/database/database.sqlite',
            'DB_ENTITY_PATHS' => $root.'/app',
        ], function () use ($root): void {
            $configuration = MigrationConfiguration::load($root);
            $factory = (new MigrationDependencyFactoryFactory($configuration))->create();

            self::assertFileDoesNotExist($root.'/database/database.sqlite');
            self::assertSame('doctrine_migration_versions', $factory->getConfiguration()->getMetadataStorageConfiguration()->getTableName());
            self::assertFileDoesNotExist($root.'/database/database.sqlite');
            self::assertInstanceOf(EntityManagerInterface::class, $factory->getEntityManager());
            self::assertFileDoesNotExist($root.'/database/database.sqlite');
            self::assertInstanceOf(Connection::class, $factory->getConnection());
            self::assertFalse($factory->getConnection()->isConnected());
            self::assertFileDoesNotExist($root.'/database/database.sqlite');
        });
    }

    public function test_dependency_factory_uses_generated_nested_table_storage_name(): void
    {
        $root = $this->projectRoot();
        mkdir($root.'/app', 0777, true);
        file_put_contents($root.'/config/migrations.php', <<<'PHP'
<?php
return [
    'migrations_paths' => ['App\\Migrations' => 'database/migrations'],
    'storage' => ['table_storage' => ['table_name' => 'generated_migration_versions']],
];
PHP);

        $this->withEnvironment([
            'DB_DRIVER' => 'pdo_sqlite',
            'DB_PATH' => $root.'/database/database.sqlite',
            'DB_ENTITY_PATHS' => $root.'/app',
        ], function () use ($root): void {
            $dependencyFactory = (new MigrationDependencyFactoryFactory(MigrationConfiguration::load($root)))->create();
            self::assertSame(
                'generated_migration_versions',
                $dependencyFactory->getConfiguration()->getMetadataStorageConfiguration()->getTableName(),
            );
            self::assertFileDoesNotExist($root.'/database/database.sqlite');
        });
    }

    public function test_doctrine_top_level_table_storage_configuration_is_preserved(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root.'/config/migrations.php', <<<'PHP'
<?php
return [
    'migrations_paths' => ['App\\Migrations' => 'database/migrations'],
    'table_storage' => ['table_name' => 'custom_versions', 'version_column_name' => 'migration_id'],
];
PHP);

        $configuration = MigrationConfiguration::load($root);

        self::assertSame('custom_versions', $configuration->tableName());
        self::assertSame('migration_id', $configuration->versionColumnName());
    }

    public function test_production_mode_uses_project_env_when_not_exported_by_process(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root.'/.env', "APP_ENV=production\n");
        $this->withEnvironment(['APP_ENV' => null], function () use ($root): void {
            self::assertFalse(MigrationConfiguration::load($root)->isDevelopmentMode());
        });
    }

    public function test_database_defaults_and_entity_paths_match_entity_manager_factory_environment_semantics(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root.'/.env', "DB_DRIVER=pdo_sqlite\nDB_PATH=database/from-env.sqlite\nDB_ENTITY_PATHS={$root}/app,{$root}/modules/Domain\n");
        mkdir($root.'/app', 0777, true);
        mkdir($root.'/modules/Domain', 0777, true);
        $this->withEnvironment([
            'DB_DRIVER' => null,
            'DB_PATH' => null,
            'DB_ENTITY_PATHS' => null,
            'DB_HOST' => null,
            'DB_PORT' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_NAME' => null,
        ], function () use ($root): void {
            $configuration = MigrationConfiguration::load($root);
            $paths = $configuration->entityPaths();

            self::assertSame([$root.'/app', $root.'/modules/Domain'], $paths);
            self::assertSame('pdo_sqlite', $configuration->connectionParameters()['driver']);
            self::assertSame($this->normalizePath($root.'/database/from-env.sqlite'), $this->normalizePath($configuration->connectionParameters()['path']));
            self::assertSame('127.0.0.1', $configuration->connectionParameters()['host']);
            self::assertSame(3306, $configuration->connectionParameters()['port']);
            self::assertSame('root', $configuration->connectionParameters()['user']);
            self::assertSame('', $configuration->connectionParameters()['password']);
            self::assertSame('tusk', $configuration->connectionParameters()['dbname']);

            $manager = (new EntityManagerFactory)();
            self::assertSame($paths, $manager->getConfiguration()->getMetadataDriverImpl()->getPaths());
            $manager->getConnection()->close();
            self::assertFileDoesNotExist($root.'/database/from-env.sqlite');
        });
    }

    public function test_malformed_migration_shape_fails_with_configuration_guidance(): void
    {
        $root = $this->projectRoot();
        file_put_contents($root.'/config/migrations.php', '<?php return ["migrations_paths" => "not-a-map"];');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrations_paths');
        MigrationConfiguration::load($root);
    }

    private function projectRoot(): string
    {
        $root = $this->temporaryDirectory();
        mkdir($root.'/database', 0777, true);
        mkdir($root.'/config', 0777, true);

        return $root;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/tusk-migration-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    /** @param array<string, string|null> $values */
    private function withEnvironment(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => [$process, $env, $server]) {
                $process === false ? putenv($name) : putenv($name.'='.$process);
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /** @param array<string, string> $paths @return array<string, string> */
    private function normalizePaths(array $paths): array
    {
        return array_map(fn (string $path): string => $this->normalizePath($path), $paths);
    }
}
