<?php

declare(strict_types=1);

namespace Tusk\Cli\Tests\Command;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Command\FrameworkCommandCatalog;
use Tusk\Cli\Command\FrameworkCommandLoader;

final class FrameworkCommandLoaderTest extends TestCase
{
    public function test_names_are_available_without_instantiating_factories_and_get_only_builds_selected_command(): void
    {
        $created = [];
        $catalog = new FrameworkCommandCatalog(getcwd() ?: '.', [
            'build' => static function () use (&$created): Command {
                $created[] = 'build';

                return new NamedTestCommand('build');
            },
            'init' => static function () use (&$created): Command {
                $created[] = 'init';

                return new NamedTestCommand('init');
            },
        ]);
        $loader = new FrameworkCommandLoader($catalog);

        self::assertSame(['build', 'config:validate', 'init', 'make:controller', 'make:entity', 'run', 'queue:work', 'runtime:diagnostics', 'make:migration', 'migrate', 'migrate:status', 'migrate:rollback', 'schema:sync'], $loader->getNames());
        self::assertSame([], $created);
        $loader->get('init')->run(new ArrayInput([]), new BufferedOutput);
        self::assertSame(['init'], $created);
    }

    public function test_compiled_application_commands_are_resolved_on_demand(): void
    {
        $resolved = [];
        $loader = new FrameworkCommandLoader(
            new FrameworkCommandCatalog(getcwd() ?: '.', ['build' => static fn (): Command => new NamedTestCommand('build')]),
            ['app:hello' => ['class' => 'App\\HelloCommand', 'description' => 'Hello']],
            static function (string $class) use (&$resolved): Command {
                $resolved[] = $class;

                return new NamedTestCommand('app:hello');
            },
        );

        self::assertContains('app:hello', $loader->getNames());
        self::assertContains('build', $loader->getNames());
        self::assertSame([], $resolved);
        self::assertSame('app:hello', $loader->get('app:hello')->getName());
        self::assertSame(['App\\HelloCommand'], $resolved);
    }

    public function test_compiled_application_collision_with_framework_name_fails_clearly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('config:validate');
        $this->expectExceptionMessage('Framework');

        new FrameworkCommandLoader(
            new FrameworkCommandCatalog(getcwd() ?: '.'),
            ['config:validate' => ['class' => 'App\\ValidateCommand', 'description' => 'App validation']],
        );
    }
}

final class NamedTestCommand extends Command
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
