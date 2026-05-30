<?php

namespace Tusk\Data\Migration;

use Tusk\Data\DB;

class Migrator
{
    private string $migrationsPath;

    public function __construct(string $migrationsPath)
    {
        $this->migrationsPath = rtrim($migrationsPath, '/');
    }

    public function run(): void
    {
        $this->createMigrationsTable();

        $ran = $this->getRanMigrations();
        $files = $this->getMigrationFiles();

        $pending = array_diff($files, $ran);

        if (empty($pending)) {
            echo "Nothing to migrate.\n";

            return;
        }

        foreach ($pending as $file) {
            $this->runMigration($file);
        }
    }

    private function createMigrationsTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )';

        DB::connection()->getPdo()->exec($sql);
    }

    private function getRanMigrations(): array
    {
        $stmt = DB::connection()->getPdo()->query('SELECT migration FROM migrations ORDER BY id ASC');

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function getMigrationFiles(): array
    {
        if (! is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        $migrations = array_filter($files, fn ($file) => str_ends_with($file, '.php'));

        sort($migrations);

        return $migrations;
    }

    private function runMigration(string $file): void
    {
        $path = $this->migrationsPath.'/'.$file;
        require_once $path;

        // Assuming class name is derived from file name, or the file simply returns an anonymous class
        // For simplicity, let's assume the file returns an anonymous class implementing MigrationInterface
        $migration = require $path;

        if (! $migration instanceof MigrationInterface) {
            echo "Error: Migration {$file} does not return a MigrationInterface.\n";

            return;
        }

        echo "Migrating: {$file}\n";
        $migration->up();

        $stmt = DB::connection()->getPdo()->prepare('INSERT INTO migrations (migration) VALUES (?)');
        $stmt->execute([$file]);

        echo "Migrated:  {$file}\n";
    }
}
