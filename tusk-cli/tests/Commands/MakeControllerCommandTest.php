<?php

namespace Tusk\Cli\Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tusk\Cli\Commands\MakeControllerCommand;

final class MakeControllerCommandTest extends TestCase
{
    private string $originalDirectory;

    private string $directory;

    protected function setUp(): void
    {
        $this->originalDirectory = getcwd();
        $this->directory = sys_get_temp_dir().'/tusk-controller-command-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_generates_controller_in_skeleton_directory_with_matching_namespace(): void
    {
        $tester = new CommandTester(new MakeControllerCommand);

        self::assertSame(0, $tester->execute(['name' => 'UserController'], ['interactive' => false]));
        $path = $this->directory.'/app/Controller/UserController.php';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertStringContainsString('namespace App\\Controller;', $contents);
        self::assertStringContainsString('#[Controller(\'/user\')]', $contents);
        self::assertStringContainsString('#[Get]', $contents);
    }

    public function test_generates_nested_controller_and_refuses_to_overwrite_existing_file(): void
    {
        $tester = new CommandTester(new MakeControllerCommand);

        self::assertSame(0, $tester->execute(['name' => 'Admin\\UserController'], ['interactive' => false]));
        $path = $this->directory.'/app/Controller/Admin/UserController.php';
        self::assertFileExists($path);
        self::assertStringContainsString('namespace App\\Controller\\Admin;', file_get_contents($path));

        self::assertSame(1, $tester->execute(['name' => 'Admin\\UserController'], ['interactive' => false]));
        self::assertStringContainsString('File may already exist', $tester->getDisplay());
    }
}
