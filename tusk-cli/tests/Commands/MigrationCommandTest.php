<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Command\FrameworkCommandCatalog;
use Tusk\Cli\Command\FrameworkCommandLoader;
use Tusk\Cli\Commands\MakeMigrationCommand;
use Tusk\Cli\Commands\MigrateCommand;
use Tusk\Cli\Commands\MigrationRollbackCommand;
use Tusk\Cli\Commands\MigrationStatusCommand;
use Tusk\Cli\Commands\SchemaSyncCommand;

final class MigrationCommandTest extends TestCase
{
    private string $root;

    private string $migrationNamespace;

    private string $domainNamespace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tusk-migrations-'.bin2hex(random_bytes(8));
        $fixtureSuffix = bin2hex(random_bytes(4));
        $this->migrationNamespace = 'App\\Migrations'.$fixtureSuffix;
        $this->domainNamespace = 'App\\Domain'.$fixtureSuffix;
        foreach (['DB_DRIVER', 'DB_PATH', 'DB_PASSWORD', 'DB_NAME', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_ENTITY_PATHS', 'APP_ENV'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        mkdir($this->root.'/config', 0777, true);
        mkdir($this->root.'/database/migrations', 0777, true);
        mkdir($this->root.'/src/Domain', 0777, true);
        file_put_contents($this->root.'/config/migrations.php', "<?php return ['migrations_paths' => [".var_export($this->migrationNamespace, true)." => 'database/migrations']];");
        file_put_contents($this->root.'/src/Domain/Widget.php', sprintf(<<<'PHP'
<?php
namespace %s;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'widgets')]
class Widget
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $name;
}
PHP, $this->domainNamespace));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function test_framework_migration_commands_use_project_root_composition_without_compiled_container(): void
    {
        $loader = new FrameworkCommandLoader(new FrameworkCommandCatalog($this->root));

        $status = new CommandTester($loader->get('migrate:status'));
        self::assertSame(0, $status->execute([]));
        self::assertFileDoesNotExist($this->root.'/database/database.sqlite');
        self::assertStringContainsString('not initialized', strtolower($status->getDisplay()));

        self::assertSame(0, (new CommandTester($loader->get('migrate')))->execute([]));
        self::assertFileDoesNotExist($this->root.'/database/database.sqlite');
        self::assertSame(1, (new CommandTester($loader->get('migrate:rollback')))->execute(['target' => '0', '--allow-down' => true]));
        self::assertFileDoesNotExist($this->root.'/database/database.sqlite');
        self::assertSame(0, (new CommandTester(new SchemaSyncCommand($this->root)))->execute(['--dry-run' => true]));
        self::assertFileDoesNotExist($this->root.'/database/database.sqlite');

        $this->createDatabase();
        $status = new CommandTester($loader->get('migrate:status'));
        self::assertSame(0, $status->execute([]));
        self::assertFalse($this->tableExists('doctrine_migration_versions'));
        $make = new CommandTester(new MakeMigrationCommand($this->root));
        self::assertSame(0, $make->execute(['description' => 'create widgets']));
        $files = glob($this->root.'/database/migrations/*.php');
        self::assertCount(1, $files);
        self::assertStringContainsString('CREATE TABLE widgets', file_get_contents($files[0]));

        $status = new CommandTester($loader->get('migrate:status'));
        self::assertSame(0, $status->execute([]));
        self::assertFalse($this->tableExists('doctrine_migration_versions'));
        self::assertStringContainsString('pending', strtolower($status->getDisplay()));

        $migrate = new CommandTester($loader->get('migrate'));
        self::assertSame(0, $migrate->execute([]), $migrate->getDisplay());
        self::assertTrue($this->tableExists('doctrine_migration_versions'));
        self::assertTrue($this->tableExists('widgets'));
        $firstRows = $this->versionCount();
        self::assertSame(1, $firstRows);

        self::assertSame(0, (new CommandTester($loader->get('migrate')))->execute([]));
        self::assertSame($firstRows, $this->versionCount());
    }

    public function test_generation_reports_no_changes_without_creating_an_empty_file(): void
    {
        $this->createDatabase();
        $connection = new \PDO('sqlite:'.$this->root.'/database/database.sqlite');
        $connection->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL)');
        $connection = null;

        $command = new CommandTester(new MakeMigrationCommand($this->root));
        self::assertSame(0, $command->execute(['description' => 'no changes']));
        self::assertStringContainsString('no schema changes', strtolower($command->getDisplay()));
        self::assertSame([], glob($this->root.'/database/migrations/*.php'));
    }

    public function test_dry_run_and_sql_export_do_not_mutate_database_or_history(): void
    {
        $this->createDatabase();
        $this->createMigration('Version20260101000000', 'CREATE TABLE dry_run_probe (id INTEGER NOT NULL)');
        $sqlPath = $this->root.'/migration.sql';

        $command = new CommandTester(new MigrateCommand($this->root));
        self::assertSame(0, $command->execute(['--dry-run' => true]));
        self::assertFalse($this->tableExists('dry_run_probe'));
        self::assertFalse($this->tableExists('doctrine_migration_versions'));

        $command = new CommandTester(new MigrateCommand($this->root));
        self::assertSame(0, $command->execute(['--write-sql' => $sqlPath]));
        self::assertFileExists($sqlPath);
        self::assertFalse($this->tableExists('dry_run_probe'));
        self::assertFalse($this->tableExists('doctrine_migration_versions'));
    }

    public function test_status_and_schema_sync_dry_run_respect_missing_custom_sqlite_path(): void
    {
        $customPath = $this->root.'/custom/db.sqlite';
        file_put_contents($this->root.'/.env', "DB_DRIVER=pdo_sqlite\nDB_PATH=custom/db.sqlite\n");
        $this->createMigration('Version20260101000000', 'CREATE TABLE custom_pending_probe (id INTEGER NOT NULL)');

        $status = new CommandTester(new MigrationStatusCommand($this->root));
        self::assertSame(0, $status->execute([]));
        self::assertStringContainsString('does not exist', strtolower($status->getDisplay()));
        self::assertStringContainsString('pending', strtolower($status->getDisplay()));
        self::assertFileDoesNotExist($customPath);

        $schema = new CommandTester(new SchemaSyncCommand($this->root));
        self::assertSame(0, $schema->execute(['--dry-run' => true]), $schema->getDisplay());
        self::assertFileDoesNotExist($customPath);
    }

    public function test_migration_status_does_not_create_missing_custom_db_path_from_process_environment(): void
    {
        $customPath = $this->root.'/external.sqlite';
        putenv('DB_DRIVER=pdo_sqlite');
        putenv('DB_PATH='.$customPath);
        $_ENV['DB_DRIVER'] = 'pdo_sqlite';
        $_ENV['DB_PATH'] = $customPath;
        $this->createMigration('Version20260101000000', 'CREATE TABLE pending_probe (id INTEGER NOT NULL)');

        $status = new CommandTester(new MigrationStatusCommand($this->root));
        self::assertSame(0, $status->execute([]));
        self::assertStringContainsString('pending', strtolower($status->getDisplay()));
        self::assertFileDoesNotExist($customPath);
    }

    public function test_migration_acknowledgement_and_schema_sync_production_gates(): void
    {
        $this->createDatabase();
        $this->createMigration('Version20260101000000', 'CREATE TABLE prod_probe (id INTEGER NOT NULL)');
        $_SERVER['APP_ENV'] = 'production';

        try {
            $apply = new CommandTester(new MigrateCommand($this->root));
            self::assertSame(1, $apply->execute([]));
            self::assertFalse($this->tableExists('doctrine_migration_versions'));
            $productionApply = new CommandTester(new MigrateCommand($this->root));
            self::assertSame(0, $productionApply->execute(['--allow-production' => true]), $productionApply->getDisplay());

            $schema = new CommandTester(new SchemaSyncCommand($this->root));
            self::assertSame(1, $schema->execute(['--force' => true]));
            self::assertFalse($this->tableExists('schema_sync_probe'));
        } finally {
            unset($_SERVER['APP_ENV']);
        }

        $schema = new CommandTester(new SchemaSyncCommand($this->root));
        self::assertSame(1, $schema->execute([]));
        self::assertSame(0, (new CommandTester(new SchemaSyncCommand($this->root)))->execute(['--dry-run' => true]));
        self::assertFalse($this->tableExists('widgets'));
        self::assertSame(0, (new CommandTester(new SchemaSyncCommand($this->root)))->execute(['--force' => true]));
        self::assertTrue($this->tableExists('widgets'));
    }

    public function test_rollback_requires_target_and_acknowledgements_and_rejects_irreversible_failure(): void
    {
        $this->createDatabase();
        $this->createMigration('Version20260101000000', 'CREATE TABLE rollback_probe (id INTEGER NOT NULL)', 'DROP TABLE rollback_probe');
        self::assertSame(0, (new CommandTester(new MigrateCommand($this->root)))->execute([]));
        $target = '0';

        self::assertSame(1, (new CommandTester(new MigrationRollbackCommand($this->root)))->execute(['target' => '0']));
        self::assertSame(1, (new CommandTester(new MigrationRollbackCommand($this->root)))->execute(['target' => $target]));
        $_SERVER['APP_ENV'] = 'production';
        self::assertSame(1, (new CommandTester(new MigrationRollbackCommand($this->root)))->execute(['target' => $target, '--allow-down' => true]));
        self::assertSame(0, (new CommandTester(new MigrationRollbackCommand($this->root)))->execute(['target' => $target, '--allow-down' => true, '--allow-production' => true, '--dry-run' => true]));
        unset($_SERVER['APP_ENV']);
        self::assertTrue($this->tableExists('rollback_probe'));
        self::assertSame(0, (new CommandTester(new MigrationRollbackCommand($this->root)))->execute(['target' => $target, '--allow-down' => true]));
        self::assertFalse($this->tableExists('rollback_probe'));
        self::assertSame(0, $this->versionCount());

        $this->createMigration('Version20260103000000', 'CREATE TABLE export_direction_probe (id INTEGER NOT NULL)', 'DROP TABLE export_direction_probe');
        self::assertSame(0, (new CommandTester(new MigrateCommand($this->root)))->execute([]));
        $rollbackSql = $this->root.'/rollback.sql';
        $rollbackExport = new CommandTester(new MigrationRollbackCommand($this->root));
        self::assertSame(0, $rollbackExport->execute([
            'target' => $this->migrationNamespace.'\\Version20260101000000',
            '--allow-down' => true,
            '--write-sql' => $rollbackSql,
        ]), $rollbackExport->getDisplay());
        self::assertFileExists($rollbackSql);
        self::assertStringContainsString('DROP TABLE export_direction_probe', file_get_contents($rollbackSql));
        self::assertStringNotContainsString('CREATE TABLE export_direction_probe', file_get_contents($rollbackSql));
        self::assertTrue($this->tableExists('export_direction_probe'));
        $actualRollback = new CommandTester(new MigrationRollbackCommand($this->root));
        self::assertSame(0, $actualRollback->execute([
            'target' => $this->migrationNamespace.'\\Version20260101000000',
            '--allow-down' => true,
        ]), $actualRollback->getDisplay());
        self::assertFalse($this->tableExists('export_direction_probe'));

        $upwardTarget = new CommandTester(new MigrationRollbackCommand($this->root));
        self::assertSame(1, $upwardTarget->execute([
            'target' => $this->migrationNamespace.'\\Version20260103000000',
            '--allow-down' => true,
            '--write-sql' => $this->root.'/invalid-rollback.sql',
        ]));
        self::assertFileDoesNotExist($this->root.'/invalid-rollback.sql');

        $this->createMigration('Version20260102000000', 'CREATE TABLE irreversible_probe (id INTEGER NOT NULL)');
        $this->createDatabase();
        self::assertSame(0, (new CommandTester(new MigrateCommand($this->root)))->execute([]));
        $rollback = new CommandTester(new MigrationRollbackCommand($this->root));
        self::assertSame(1, $rollback->execute(['target' => '0', '--allow-down' => true]));
        self::assertStringNotContainsString('password', strtolower($rollback->getDisplay()));
    }

    public function test_driver_errors_are_redacted_and_non_zero(): void
    {
        file_put_contents($this->root.'/config/migrations.php', "<?php return ['migrations_paths' => [".var_export($this->migrationNamespace, true)." => 'database/migrations']];");
        file_put_contents($this->root.'/.env', "DB_DRIVER=driver.invalid\nDB_PASSWORD=do-not-print\n");
        putenv('DB_DRIVER');
        putenv('DB_PATH');
        putenv('DB_PASSWORD');
        unset($_ENV['DB_DRIVER'], $_SERVER['DB_DRIVER'], $_ENV['DB_PATH'], $_SERVER['DB_PATH'], $_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD']);

        $command = new CommandTester(new MigrationStatusCommand($this->root));
        self::assertSame(1, $command->execute([]));
        self::assertStringNotContainsString('do-not-print', $command->getDisplay());
        self::assertStringNotContainsString('SQLSTATE', $command->getDisplay());
        putenv('DB_DRIVER');
        putenv('DB_PATH');
        putenv('DB_PASSWORD');
        unset($_ENV['DB_DRIVER'], $_SERVER['DB_DRIVER'], $_ENV['DB_PATH'], $_SERVER['DB_PATH'], $_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD']);
    }

    public function test_migration_execution_errors_are_redacted_and_non_zero(): void
    {
        $this->createDatabase();
        $this->createFailingMigration('Version20260101000000', 'sensitive-driver-detail');

        $command = new CommandTester(new MigrateCommand($this->root));
        self::assertSame(1, $command->execute([]));
        self::assertStringNotContainsString('sensitive-driver-detail', $command->getDisplay());
        self::assertStringNotContainsString('SQLSTATE', $command->getDisplay());
    }

    private function createDatabase(): void
    {
        if (! is_dir($this->root.'/database')) {
            mkdir($this->root.'/database', 0777, true);
        }
        $pdo = new \PDO('sqlite:'.$this->root.'/database/database.sqlite');
        $pdo = null;
    }

    private function createMigration(string $class, string $up, ?string $down = null): void
    {
        $downCode = $down === null ? "throw new \\Doctrine\\Migrations\\Exception\\IrreversibleMigration('not reversible');" : '$this->addSql('.var_export($down, true).');';
        file_put_contents($this->root.'/database/migrations/'.$class.'.php', "<?php\nnamespace ".$this->migrationNamespace.";\nuse Doctrine\\DBAL\\Schema\\Schema;\nuse Doctrine\\Migrations\\AbstractMigration;\nfinal class {$class} extends AbstractMigration { public function up(Schema \$schema): void { \$this->addSql(".var_export($up, true)."); } public function down(Schema \$schema): void { {$downCode} } }");
    }

    private function createFailingMigration(string $class, string $message): void
    {
        file_put_contents($this->root.'/database/migrations/'.$class.'.php', "<?php\nnamespace ".$this->migrationNamespace.";\nuse Doctrine\\DBAL\\Schema\\Schema;\nuse Doctrine\\Migrations\\AbstractMigration;\nfinal class {$class} extends AbstractMigration { public function up(Schema \$schema): void { throw new \\RuntimeException(".var_export($message, true).'); } public function down(Schema $schema): void {} }');
    }

    private function tableExists(string $name): bool
    {
        if (! is_file($this->root.'/database/database.sqlite')) {
            return false;
        }
        $pdo = new \PDO('sqlite:'.$this->root.'/database/database.sqlite');
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$name]);

        return $statement->fetchColumn() !== false;
    }

    private function versionCount(): int
    {
        if (! $this->tableExists('doctrine_migration_versions')) {
            return 0;
        }
        $pdo = new \PDO('sqlite:'.$this->root.'/database/database.sqlite');

        return (int) $pdo->query('SELECT COUNT(*) FROM doctrine_migration_versions')->fetchColumn();
    }
}
