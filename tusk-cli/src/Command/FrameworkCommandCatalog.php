<?php

declare(strict_types=1);

namespace Tusk\Cli\Command;

use Closure;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Tusk\Cli\Commands\ConfigValidateCommand;
use Tusk\Cli\Commands\InitCommand;
use Tusk\Cli\Commands\MakeControllerCommand;
use Tusk\Cli\Commands\MakeEntityCommand;
use Tusk\Cli\Commands\MakeMigrationCommand;
use Tusk\Cli\Commands\MigrateCommand;
use Tusk\Cli\Commands\MigrationRollbackCommand;
use Tusk\Cli\Commands\MigrationStatusCommand;
use Tusk\Cli\Commands\QueueWorkerCommand;
use Tusk\Cli\Commands\RunCommand;
use Tusk\Cli\Commands\RuntimeDiagnosticsCommand;
use Tusk\Cli\Commands\SchemaSyncCommand;

/** @internal @phpstan-type Factory Closure(): Command */
final class FrameworkCommandCatalog
{
    /** @var array<string, array{description: string, factory: Closure(): Command}> */
    private array $factories;

    /**
     * @param  array<string, Closure(): Command>  $factoryOverrides  Test and extension seam; names remain explicit.
     */
    public function __construct(string $projectRoot, array $factoryOverrides = [], ?Closure $resolveService = null)
    {
        $resolveService ??= static fn (string $class): never => throw new RuntimeException(
            "Framework command {$class} requires the compiled application container."
        );

        $factories = [
            'build' => ['Compiles the Dependency Injection Container, Routes, and Commands.', static fn (): Command => new BuildCommand($projectRoot)],
            'config:validate' => ['Validate the application resilience configuration.', static fn (): Command => new ConfigValidateCommand($projectRoot)],
            'init' => ['Initialize a new Tusk project.', static fn (): Command => new InitCommand],
            'make:controller' => ['Create a new HTTP Controller class.', static fn (): Command => new MakeControllerCommand($projectRoot)],
            'make:entity' => ['Create a new Doctrine Entity class.', static fn (): Command => new MakeEntityCommand($projectRoot)],
            'run' => ['Run scripts through the Tusk Engine.', static fn (): Command => new RunCommand],
            'queue:work' => ['Start the queue worker.', static fn (): Command => $resolveService(QueueWorkerCommand::class)],
            'runtime:diagnostics' => ['Show persistent runtime diagnostics.', static fn (): Command => new RuntimeDiagnosticsCommand],
            'make:migration' => ['Generate a database migration from ORM changes.', static fn (): Command => new MakeMigrationCommand($projectRoot)],
            'migrate' => ['Apply pending database migrations.', static fn (): Command => new MigrateCommand($projectRoot)],
            'migrate:status' => ['Show applied and pending database migrations.', static fn (): Command => new MigrationStatusCommand($projectRoot)],
            'migrate:rollback' => ['Roll back to a named migration version.', static fn (): Command => new MigrationRollbackCommand($projectRoot)],
            'schema:sync' => ['Synchronize the local development schema.', static fn (): Command => new SchemaSyncCommand($projectRoot)],
        ];

        foreach ($factoryOverrides as $name => $factory) {
            if (! isset($factories[$name])) {
                throw new RuntimeException("Cannot override unknown Framework command '{$name}'.");
            }
            $factories[$name][1] = $factory;
        }

        foreach ($factories as $name => [$description, $factory]) {
            $this->factories[$name] = ['description' => $description, 'factory' => $factory];
        }
    }

    /** @return list<string> */
    public function getNames(): array
    {
        return array_keys($this->factories);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function get(string $name): Command
    {
        if (! isset($this->factories[$name])) {
            throw new RuntimeException("Framework command '{$name}' is not registered.");
        }

        return new LazyCommand(
            $name,
            [],
            $this->factories[$name]['description'],
            false,
            $this->factories[$name]['factory'],
        );
    }
}
