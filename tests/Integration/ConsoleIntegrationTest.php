<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ConsoleIntegrationTest extends TestCase
{
    public function test_cli_list_command_executes_successfully(): void
    {
        $worktree = getcwd().DIRECTORY_SEPARATOR.'.worktrees'.DIRECTORY_SEPARATOR.'scanner-fixture-'.bin2hex(random_bytes(4));
        $worktreesDirectory = dirname($worktree);
        $createdWorktreesDirectory = ! is_dir($worktreesDirectory);
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

        try {
            $outputBuild = [];
            $returnCodeBuild = -1;
            exec('php bin/tusk build', $outputBuild, $returnCodeBuild);
            self::assertEquals(0, $returnCodeBuild, 'Failed to run tusk build: '.implode("\n", $outputBuild));

            $output = [];
            $returnCode = -1;
            exec('php bin/tusk list', $output, $returnCode);

            $outputStr = implode("\n", $output);

            self::assertEquals(0, $returnCode, 'Command failed with output: '.$outputStr);
            self::assertStringContainsString('Tusk Framework CLI', $outputStr);
            self::assertStringContainsString('make:controller', $outputStr);
            self::assertStringContainsString('make:entity', $outputStr);
        } finally {
            unlink($source);
            rmdir($worktree);

            if ($createdWorktreesDirectory) {
                rmdir($worktreesDirectory);
            }
        }
    }
}
