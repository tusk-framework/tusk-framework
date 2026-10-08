<?php

namespace Tusk\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Tusk\Cli\CommandCompiler;

final class CommandCompilerTest extends TestCase
{
    public function test_ide_metadata_files_are_not_loaded_as_commands(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-commands-'.bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);

        $metadata = $directory.DIRECTORY_SEPARATOR.'.phpstorm.meta.php';
        file_put_contents($metadata, <<<'PHP'
<?php

namespace Tusk\Cli\Tests\Fixtures;

use Tusk\Cli\Attribute\AsCommand;

#[AsCommand('ide-metadata')]
final class IdeMetadataCommand {}
PHP);

        try {
            self::assertSame([], (new CommandCompiler)->scan([$directory]));
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }

    public function test_dependency_vendor_directories_are_not_scanned_as_commands(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-commands-'.bin2hex(random_bytes(4));
        $vendor = $directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'fixture';
        mkdir($vendor, 0755, true);

        $source = $vendor.DIRECTORY_SEPARATOR.'VendorCommand.php';
        file_put_contents($source, <<<'PHP'
<?php

namespace Tusk\Cli\Tests\Fixtures\Vendor;

use Tusk\Cli\Attribute\AsCommand;

#[AsCommand('vendor-command')]
final class VendorCommand {}
PHP);

        try {
            self::assertSame([], (new CommandCompiler)->scan([$directory]));
        } finally {
            unlink($source);
            rmdir($vendor);
            rmdir(dirname($vendor));
            rmdir($directory);
        }
    }

    public function test_local_git_worktree_directories_are_not_scanned_as_commands(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-commands-'.bin2hex(random_bytes(4));
        $worktree = $directory.DIRECTORY_SEPARATOR.'.worktrees'.DIRECTORY_SEPARATOR.'feature';
        mkdir($worktree, 0755, true);

        $namespace = 'TuskCliWorktree'.bin2hex(random_bytes(4));
        $source = $worktree.DIRECTORY_SEPARATOR.'WorktreeCommand.php';
        file_put_contents($source, sprintf(<<<'PHP'
<?php

namespace %s;

use Tusk\Cli\Attribute\AsCommand;

#[AsCommand('worktree-command')]
final class WorktreeCommand {}
PHP, $namespace));

        try {
            self::assertSame([], (new CommandCompiler)->scan([$directory]));
        } finally {
            unlink($source);
            rmdir($worktree);
            rmdir(dirname($worktree));
            rmdir($directory);
        }
    }
}
