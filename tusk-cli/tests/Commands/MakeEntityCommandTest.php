<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Commands\MakeEntityCommand;

final class MakeEntityCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tusk-entity-command-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_uses_explicit_project_root_and_generates_nested_entity(): void
    {
        $root = $this->directory.'/generated-app';
        mkdir($root);
        $tester = new CommandTester(new MakeEntityCommand($root));

        self::assertSame(0, $tester->execute(['name' => 'Billing\\Invoice'], ['interactive' => false]));
        self::assertFileExists($root.'/src/Domain/Billing/Invoice.php');
        self::assertStringContainsString('namespace App\\Domain\\Billing;', file_get_contents($root.'/src/Domain/Billing/Invoice.php'));
        self::assertFileDoesNotExist($this->directory.'/src/Domain/Billing/Invoice.php');
    }

    public function test_forward_slash_namespace_segments_match_the_generated_path(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->directory));

        self::assertSame(0, $tester->execute(['name' => 'Billing/Invoice'], ['interactive' => false]));
        self::assertStringContainsString('namespace App\\Domain\\Billing;', file_get_contents($this->directory.'/src/Domain/Billing/Invoice.php'));
    }

    public function test_rejects_absolute_and_traversing_entity_names(): void
    {
        foreach (['../OutsideEntity', '..\\OutsideEntity', '/tmp/OutsideEntity', 'C:\\OutsideEntity'] as $name) {
            $tester = new CommandTester(new MakeEntityCommand($this->directory));

            self::assertSame(1, $tester->execute(['name' => $name], ['interactive' => false]), $name);
            self::assertStringContainsString('safe relative class name', $tester->getDisplay());
        }

        self::assertDirectoryDoesNotExist(dirname($this->directory).'/src');
    }
}
