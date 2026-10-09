<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ConfigValidateCommandIntegrationTest extends TestCase
{
    public function test_config_validate_is_available_before_application_compilation(): void
    {
        $basePath = dirname(__DIR__, 2);
        $projectPath = sys_get_temp_dir().'/tusk-config-cli-'.bin2hex(random_bytes(8));
        mkdir($projectPath.'/config', 0777, true);
        mkdir($projectPath.'/.tusk');
        file_put_contents($projectPath.'/config/resilience.php', '<?php return ["policies" => []];');
        $markerPath = $projectPath.'/.tusk/loaded';
        file_put_contents($projectPath.'/.tusk/CompiledCommandRegistry.php', '<?php file_put_contents(__DIR__."/loaded", "registry");');
        file_put_contents($projectPath.'/.tusk/CompiledContainer.php', '<?php file_put_contents(__DIR__."/loaded", "container");');
        $originalPath = getcwd();

        try {
            chdir($projectPath);
            $output = [];
            $status = -1;
            exec('php '.escapeshellarg($basePath.'/bin/tusk').' config:validate', $output, $status);

            self::assertSame(0, $status, implode("\n", $output));
            self::assertStringContainsString('Resilience configuration is valid', implode("\n", $output));
            self::assertFileDoesNotExist($markerPath, 'Validation loaded compiled application code.');
        } finally {
            if ($originalPath !== false) {
                chdir($originalPath);
            }
            foreach ([$markerPath, $projectPath.'/.tusk/CompiledContainer.php', $projectPath.'/.tusk/CompiledCommandRegistry.php', $projectPath.'/config/resilience.php'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($projectPath.'/.tusk');
            rmdir($projectPath.'/config');
            rmdir($projectPath);
        }
    }
}
