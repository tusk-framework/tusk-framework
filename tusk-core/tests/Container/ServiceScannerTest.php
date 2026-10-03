<?php

namespace Tusk\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Container\ServiceScanner;

final class ServiceScannerTest extends TestCase
{
    public function test_ide_metadata_files_are_not_loaded_as_application_services(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-scanner-'.bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);

        $metadata = $directory.DIRECTORY_SEPARATOR.'.phpstorm.meta.php';
        file_put_contents($metadata, <<<'PHP'
<?php

namespace Tusk\Core\Tests\Fixtures;

use Tusk\Contracts\Attributes\Service;

#[Service]
final class IdeMetadataService {}
PHP);

        try {
            self::assertSame([], (new ServiceScanner())->scan([$directory]));
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }
}
