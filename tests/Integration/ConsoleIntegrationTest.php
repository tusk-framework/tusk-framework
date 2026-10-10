<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ConsoleIntegrationTest extends TestCase
{
    public function test_framework_commands_are_listed_without_compiled_application_artifacts(): void
    {
        $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-cli-list-'.bin2hex(random_bytes(6));
        mkdir($projectRoot, 0755, true);
        $originalPath = getcwd();
        $cliPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tusk';

        try {
            chdir($projectRoot);
            $output = [];
            $status = -1;
            exec('php '.escapeshellarg($cliPath).' list', $output, $status);
            $outputText = implode("\n", $output);

            self::assertSame(0, $status, $outputText);
            foreach ([
                'build', 'config:validate', 'init', 'make:controller', 'make:entity', 'run', 'queue:work',
                'runtime:diagnostics', 'make:migration', 'migrate', 'migrate:status', 'migrate:rollback', 'schema:sync',
            ] as $command) {
                self::assertStringContainsString($command, $outputText);
            }
            self::assertFileDoesNotExist($projectRoot.'/.tusk/CompiledCommandRegistry.php');
            self::assertFileDoesNotExist($projectRoot.'/.tusk/CompiledContainer.php');
        } finally {
            if ($originalPath !== false) {
                chdir($originalPath);
            }
            rmdir($projectRoot);
        }
    }

    public function test_cli_list_command_executes_successfully(): void
    {
        $projectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-console-'.bin2hex(random_bytes(6));
        $worktree = $projectRoot.DIRECTORY_SEPARATOR.'.worktrees'.DIRECTORY_SEPARATOR.'scanner-fixture';
        mkdir($projectRoot, 0755, true);
        mkdir($worktree, 0755, true);

        $namespace = 'TuskRuntimeScannerFixture'.bin2hex(random_bytes(4));
        $source = $worktree.DIRECTORY_SEPARATOR.'InvalidContainer.php';
        file_put_contents($source, sprintf(<<<'PHP'
<?php

namespace %s;

use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;

#[Service]
final class InvalidContainer implements ContainerInterface {}
PHP, $namespace));

        $originalPath = getcwd();
        $cliPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'tusk';
        try {
            chdir($projectRoot);
            $outputBuild = [];
            $returnCodeBuild = -1;
            exec('php '.escapeshellarg($cliPath).' build', $outputBuild, $returnCodeBuild);
            self::assertEquals(0, $returnCodeBuild, 'Failed to run tusk build: '.implode("\n", $outputBuild));

            $output = [];
            $returnCode = -1;
            exec('php '.escapeshellarg($cliPath).' list', $output, $returnCode);

            $outputStr = implode("\n", $output);

            self::assertEquals(0, $returnCode, 'Command failed with output: '.$outputStr);
            self::assertStringContainsString('Tusk Framework CLI', $outputStr);
            self::assertStringContainsString('make:controller', $outputStr);
            self::assertStringContainsString('make:entity', $outputStr);
        } finally {
            if ($originalPath !== false) {
                chdir($originalPath);
            }
            unlink($source);
            rmdir($worktree);
            rmdir(dirname($worktree));
            foreach (['CompiledCommandRegistry.php', 'CompiledContainer.php', 'CompiledRouter.php'] as $compiledFile) {
                $path = $projectRoot.'/.tusk/'.$compiledFile;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($projectRoot.'/.tusk');
            rmdir($projectRoot);
        }
    }
}
