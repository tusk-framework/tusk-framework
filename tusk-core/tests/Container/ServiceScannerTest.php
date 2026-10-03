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
            self::assertSame([], (new ServiceScanner)->scan([$directory]));
        } finally {
            unlink($metadata);
            rmdir($directory);
        }
    }

    public function test_dependency_vendor_directories_are_not_scanned_as_application_services(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-scanner-'.bin2hex(random_bytes(4));
        mkdir($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'package', 0755, true);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'AppService.php', <<<'PHP'
<?php

namespace TuskCoreScannerFixture;

use Tusk\Contracts\Attributes\Service;

#[Service]
final class AppService {}
PHP);
        file_put_contents($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'package'.DIRECTORY_SEPARATOR.'DependencyService.php', <<<'PHP'
<?php

namespace TuskCoreScannerVendorFixture;

use Tusk\Contracts\Attributes\Service;

#[Service]
final class DependencyService {}
PHP);

        try {
            $definitions = (new ServiceScanner)->scan([$directory]);

            self::assertArrayHasKey('TuskCoreScannerFixture\AppService', $definitions);
            self::assertArrayNotHasKey('TuskCoreScannerVendorFixture\DependencyService', $definitions);
        } finally {
            unlink($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'package'.DIRECTORY_SEPARATOR.'DependencyService.php');
            rmdir($directory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'package');
            rmdir($directory.DIRECTORY_SEPARATOR.'vendor');
            unlink($directory.DIRECTORY_SEPARATOR.'AppService.php');
            rmdir($directory);
        }
    }

    public function test_nullable_service_dependencies_are_marked_as_optional(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tusk-scanner-'.bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'OptionalService.php', <<<'PHP'
<?php

namespace TuskCoreScannerOptionalFixture;

use Tusk\Contracts\Attributes\Service;

#[Service]
final class OptionalService
{
    public function __construct(private readonly ?\TuskCoreScannerOptionalFixture\OptionalDependency $dependency = null) {}
}
PHP);

        try {
            $definitions = (new ServiceScanner)->scan([$directory]);

            self::assertSame(
                ['TuskCoreScannerOptionalFixture\OptionalDependency'],
                $definitions['TuskCoreScannerOptionalFixture\OptionalService']['optional_dependencies'],
            );
        } finally {
            unlink($directory.DIRECTORY_SEPARATOR.'OptionalService.php');
            rmdir($directory);
        }
    }
}
