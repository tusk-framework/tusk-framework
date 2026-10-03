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
            self::assertSame([], (new CommandCompiler())->scan([$directory]));
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }
}
