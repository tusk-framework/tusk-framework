<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Commands\ConfigValidateCommand;

final class ConfigValidateCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/tusk-config-validate-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath.'/config/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->basePath.'/config');
        rmdir($this->basePath);
    }

    public function test_validates_base_configuration_for_default_profile(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => ["payments" => ["retry" => ["max_attempts" => 2]]]];');
        $tester = new CommandTester(new ConfigValidateCommand($this->basePath));

        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Resilience configuration is valid', $tester->getDisplay());
    }

    public function test_validates_an_explicit_profile(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => [], "profiles" => ["staging" => ["policies" => ["payments" => []]]]];');
        $tester = new CommandTester(new ConfigValidateCommand($this->basePath));

        $status = $tester->execute(['--profile' => 'staging']);

        self::assertSame(0, $status);
        self::assertStringContainsString('staging', $tester->getDisplay());
    }

    public function test_rejects_an_explicit_blank_profile(): void
    {
        $tester = new CommandTester(new ConfigValidateCommand($this->basePath));

        $status = $tester->execute(['--profile' => '']);

        self::assertSame(1, $status);
        self::assertStringContainsString('resilience.profiles.<name>', $tester->getDisplay());
    }

    public function test_invalid_configuration_reports_path_without_secret_value(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => ["payments" => ["timeout" => "do-not-leak-this"]]];');
        $tester = new CommandTester(new ConfigValidateCommand($this->basePath));

        $status = $tester->execute([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('resilience.policies.payments.timeout', $tester->getDisplay());
        self::assertStringNotContainsString('do-not-leak-this', $tester->getDisplay());
    }

    public function test_validation_does_not_create_runtime_or_generated_files(): void
    {
        file_put_contents($this->basePath.'/config/resilience.php', '<?php return ["policies" => []];');
        file_put_contents($this->basePath.'/config/runtime.php', '<?php return ["runtime" => ["type" => "unsupported"]];');
        $before = scandir($this->basePath);
        $tester = new CommandTester(new ConfigValidateCommand($this->basePath));

        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertSame($before, scandir($this->basePath));
        self::assertDirectoryDoesNotExist($this->basePath.'/.tusk');
    }
}
