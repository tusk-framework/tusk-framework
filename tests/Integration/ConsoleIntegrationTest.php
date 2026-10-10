<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tusk\Cli\Generator\ProjectGenerator;

final class ConsoleIntegrationTest extends TestCase
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

    public function test_generated_project_framework_commands_run_without_build_and_do_not_boot_the_application(): void
    {
        $root = $this->temporaryDirectory().'/generated-app';
        $this->generateProject($root);

        [$status, $output] = $this->runCli($root, 'list');
        self::assertSame(0, $status, $output);
        foreach ([
            'build', 'config:validate', 'init', 'make:controller', 'make:entity', 'run', 'queue:work',
            'runtime:diagnostics', 'make:migration', 'migrate', 'migrate:status', 'migrate:rollback', 'schema:sync',
        ] as $command) {
            self::assertStringContainsString($command, $output);
        }
        self::assertFileDoesNotExist($root.'/.tusk/CompiledCommandRegistry.php');
        self::assertFileDoesNotExist($root.'/.tusk/CompiledContainer.php');

        foreach ([['make:controller', 'Admin\\UserController'], ['make:entity', 'Billing\\Invoice']] as $arguments) {
            [$status, $output] = $this->runCli($root, ...$arguments);
            self::assertSame(0, $status, implode(' ', $arguments)."\n".$output);
        }

        self::assertFileExists($root.'/app/Controller/Admin/UserController.php');
        self::assertFileExists($root.'/src/Domain/Billing/Invoice.php');
        self::assertFileDoesNotExist($root.'/bootstrap-executed');
        touch($root.'/database/database.sqlite');
    }

    public function test_compiled_application_command_coexists_with_framework_catalog(): void
    {
        $root = $this->temporaryDirectory().'/generated-app';
        $this->generateProject($root);
        mkdir($root.'/app/Command', 0755, true);
        file_put_contents($root.'/app/Command/HelloCommand.php', <<<'PHP'
<?php
namespace App\Command;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Attribute\AsCommand;
use Tusk\Contracts\Attributes\Service;
#[AsCommand('app:hello', 'Fixture application command')]
#[Service]
final class HelloCommand extends Command
{
    public function __construct()
    {
        parent::__construct('app:hello');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('APP_COMMAND_OK');
        return self::SUCCESS;
    }
}
PHP);
        $this->writeApplicationAutoloader($root);

        [$status, $output] = $this->runCli($root, 'build');
        self::assertSame(0, $status, $output);
        [$status, $output] = $this->runCli($root, 'app:hello');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('APP_COMMAND_OK', $output);
        [$status, $output] = $this->runCli($root, 'make:entity', 'Customer');
        self::assertSame(0, $status, $output);
        self::assertFileExists($root.'/src/Domain/Customer.php');
    }

    public function test_framework_command_name_collision_is_rejected_when_loading_compiled_commands(): void
    {
        $root = $this->temporaryDirectory().'/generated-app';
        $this->generateProject($root);
        mkdir($root.'/app/Command', 0755, true);
        file_put_contents($root.'/app/Command/CollisionCommand.php', <<<'PHP'
<?php
namespace App\Command;
use Symfony\Component\Console\Command\Command;
use Tusk\Cli\Attribute\AsCommand;
#[AsCommand('make:entity', 'Conflicting command')]
final class CollisionCommand extends Command {}
PHP);

        [$status, $output] = $this->runCli($root, 'build');

        self::assertSame(0, $status, $output);
        [$status, $output] = $this->runCli($root, 'list');
        self::assertSame(255, $status, $output);
        self::assertStringContainsString('reserved by the Tusk Framework', $output);
        self::assertStringContainsString('make:entity', $output);
    }

    /** @return array{int, string} */
    private function runCli(string $projectRoot, string ...$arguments): array
    {
        $cliPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tusk';
        $command = array_merge([PHP_BINARY, $cliPath], $arguments);
        $extensionDirectory = ini_get('extension_dir');
        $sqliteExtension = rtrim((string) $extensionDirectory, '/\\').DIRECTORY_SEPARATOR.'php_pdo_sqlite.dll';
        if (PHP_OS_FAMILY === 'Windows' && is_file($sqliteExtension)) {
            $command = array_merge([
                PHP_BINARY, '-d', 'extension_dir='.$extensionDirectory, '-d', 'extension=php_pdo_sqlite.dll',
                '-d', 'extension=php_sqlite3.dll', $cliPath,
            ], $arguments);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot);
        self::assertIsResource($process, 'Could not start the Framework CLI.');
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function writeApplicationAutoloader(string $root): void
    {
        mkdir($root.'/vendor', 0755, true);
        file_put_contents($root.'/vendor/autoload.php', '<?php spl_autoload_register(static function (string $class): void { $prefix = "App\\\\"; if (str_starts_with($class, $prefix)) { $file = dirname(__DIR__)."/app/".str_replace("\\\\", DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).".php"; if (is_file($file)) { require $file; } } });');
    }

    private function generateProject(string $root): void
    {
        $originalDirectory = getcwd();
        try {
            chdir(dirname($root));
            (new ProjectGenerator)->generate(basename($root), 'api');
        } finally {
            if ($originalDirectory !== false) {
                chdir($originalDirectory);
            }
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/tusk-console-integration-'.bin2hex(random_bytes(8));
        mkdir($directory, 0755, true);
        $this->directories[] = $directory;

        return $directory;
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
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
