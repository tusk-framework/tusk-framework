<?php

declare(strict_types=1);

namespace Tusk\Cli\Doctrine;

use RuntimeException;
use Throwable;
use Tusk\Config\Env;

final class MigrationConfiguration
{
    /** @param array<string, mixed> $values @param array<string, mixed> $connectionParameters @param list<string> $entityPaths */
    private function __construct(
        private readonly string $projectRoot,
        private readonly array $values,
        private readonly array $connectionParameters,
        private readonly array $entityPaths,
    ) {}

    public static function load(string $projectRoot): self
    {
        $root = realpath($projectRoot);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Project root is not a readable directory.');
        }

        Env::load($root.'/.env');
        $file = $root.'/config/migrations.php';
        $values = [];

        if (is_file($file) && ! is_link($file)) {
            try {
                $loaded = (static fn (string $__file): mixed => require $__file)($file);
            } catch (Throwable) {
                throw new RuntimeException('Migration configuration could not be loaded; check config/migrations.php.');
            }
            if (! is_array($loaded)) {
                throw new RuntimeException('Migration configuration must return an array; check config/migrations.php.');
            }
            $values = $loaded;
        }

        $migrationPaths = $values['migrations_paths'] ?? ['App\\Migrations' => 'database/migrations'];
        if (! is_array($migrationPaths) || $migrationPaths === []) {
            throw new RuntimeException('Migration configuration key "migrations_paths" must be a non-empty namespace-to-path map.');
        }
        foreach ($migrationPaths as $namespace => $path) {
            if (! is_string($namespace) || trim($namespace) === '' || ! is_string($path) || trim($path) === '') {
                throw new RuntimeException('Migration configuration key "migrations_paths" must contain non-empty namespace-to-path entries.');
            }
            $migrationPaths[$namespace] = self::resolvePath($root, $path);
        }
        $values['migrations_paths'] = $migrationPaths;
        $storage = $values['storage'] ?? [];
        if (! is_array($storage)) {
            throw new RuntimeException('Migration configuration key "storage" must be an array.');
        }
        $tableStorage = $storage['table_storage'] ?? [];
        if (! is_array($tableStorage)) {
            throw new RuntimeException('Migration configuration key "storage.table_storage" must be an array.');
        }
        $tableStorage['table_name'] ??= 'doctrine_migration_versions';
        $tableStorage['version_column_name'] ??= 'version';
        $tableStorage['executed_at_column_name'] ??= 'executed_at';
        $tableStorage['execution_time_column_name'] ??= 'execution_time';
        // Tusk's generated config nests this for its app config convention; Doctrine 3.9 expects table_storage at the top level.
        unset($values['storage']);
        $values['table_storage'] = $tableStorage;

        $entityPathsValue = Env::get('DB_ENTITY_PATHS');
        if ($entityPathsValue === null) {
            $entityPaths = [$root.'/src/Domain'];
        } else {
            $entityPaths = array_values(array_filter(explode(',', (string) $entityPathsValue)));
        }

        $driver = Env::get('DB_DRIVER', 'pdo_sqlite');
        $connectionParameters = [
            'driver' => $driver,
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => Env::get('DB_PORT', 3306),
            'user' => Env::get('DB_USER', 'root'),
            'password' => Env::get('DB_PASSWORD', ''),
            'dbname' => Env::get('DB_NAME', 'tusk'),
        ];
        if ($driver === 'pdo_sqlite') {
            $connectionParameters['path'] = self::resolvePath($root, (string) Env::get('DB_PATH', $root.'/database/database.sqlite'));
        }

        return new self($root, $values, $connectionParameters, $entityPaths);
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array<string, mixed> */
    public function connectionParameters(): array
    {
        return $this->connectionParameters;
    }

    /** @return list<string> */
    public function entityPaths(): array
    {
        return $this->entityPaths;
    }

    /** @return array<string, string> */
    public function migrationsPaths(): array
    {
        return $this->values['migrations_paths'];
    }

    public function tableName(): string
    {
        return $this->values['table_storage']['table_name'];
    }

    public function versionColumnName(): string
    {
        return $this->values['table_storage']['version_column_name'];
    }

    public function executedAtColumnName(): string
    {
        return $this->values['table_storage']['executed_at_column_name'];
    }

    public function executionTimeColumnName(): string
    {
        return $this->values['table_storage']['execution_time_column_name'];
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    private static function resolvePath(string $root, string $path): string
    {
        if ($path === '') {
            return $root;
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\')) {
            return rtrim($path, '/\\');
        }
        return rtrim($root, '/\\').'/'.ltrim($path, '/\\');
    }
}
