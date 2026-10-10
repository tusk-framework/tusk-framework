<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tusk\Cli\Generator\ProjectGenerator;

final class DoctrineMigrationsIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tusk-doctrine-cli-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
        $originalDirectory = getcwd();
        try {
            chdir($this->directory);
            (new ProjectGenerator)->generate('application', 'api');
        } finally {
            if ($originalDirectory !== false) {
                chdir($originalDirectory);
            }
        }
        $this->directory .= '/application';
        file_put_contents($this->directory.'/bootstrap/app.php', "<?php\nfile_put_contents(__DIR__.'/../bootstrap-executed', 'yes');\n".file_get_contents($this->directory.'/bootstrap/app.php'));
    }

    protected function tearDown(): void
    {
        $root = dirname($this->directory);
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
        parent::tearDown();
    }

    public function test_fresh_generated_app_can_check_status_without_database_and_generate_migration_without_build(): void
    {
        [$status, $output] = $this->runCli('list');
        self::assertSame(0, $status, $output);
        self::assertFileDoesNotExist($this->directory.'/.tusk/CompiledCommandRegistry.php');

        [$status, $output] = $this->runCli('migrate:status');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('does not exist', $output);
        self::assertFileDoesNotExist($this->directory.'/database/database.sqlite');

        [$status, $output] = $this->runCli('make:entity', 'Billing\\Invoice');
        self::assertSame(0, $status, $output);
        self::assertFileExists($this->directory.'/src/Domain/Billing/Invoice.php');

        touch($this->directory.'/database/database.sqlite');
        [$status, $output] = $this->runCli('make:migration', 'Create invoices');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Migration generated:', $output);
        self::assertSame(1, count(glob($this->directory.'/database/migrations/Version*.php') ?: []));
        self::assertFileDoesNotExist($this->directory.'/bootstrap-executed');
    }

    /** @return array{int, string} */
    private function runCli(string ...$arguments): array
    {
        $cliPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tusk';
        $command = array_merge([PHP_BINARY, $cliPath], $arguments);
        $extensionDirectory = ini_get('extension_dir');
        if (PHP_OS_FAMILY === 'Windows' && is_file(rtrim((string) $extensionDirectory, '/\\').DIRECTORY_SEPARATOR.'php_pdo_sqlite.dll')) {
            $command = array_merge([
                PHP_BINARY, '-d', 'extension_dir='.$extensionDirectory, '-d', 'extension=php_pdo_sqlite.dll',
                '-d', 'extension=php_sqlite3.dll', $cliPath,
            ], $arguments);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
        self::assertIsResource($process, 'Could not start the Framework CLI.');
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
