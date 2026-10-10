<?php

declare(strict_types=1);

namespace Tusk\Cli\Commands;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\NoMigrationsToExecute;
use Doctrine\Migrations\MigratorConfiguration;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tusk\Cli\Doctrine\ProjectMigrationFactory;
use Tusk\Config\Env;

abstract class AbstractMigrationCommand extends Command
{
    private ?ProjectMigrationFactory $migrationFactory = null;

    public function __construct(protected readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function projectFactory(): ProjectMigrationFactory
    {
        return $this->migrationFactory ??= new ProjectMigrationFactory($this->projectRoot);
    }

    protected function isProduction(): bool
    {
        Env::load(rtrim($this->projectRoot, '/\\').'/.env');

        return (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'development')) === 'production';
    }

    protected function failSafely(OutputInterface $output, string $operation, \Throwable $exception): int
    {
        // Driver and configuration exceptions may contain URLs, credentials, and SQL details.
        $output->writeln('<error>'.$operation.' failed ('.get_class($exception).'). Check the project database and migration configuration.</error>');

        return self::FAILURE;
    }

    protected function safeExecute(InputInterface $input, OutputInterface $output, string $operation, callable $callback): int
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            return $this->failSafely($output, $operation, $exception);
        } finally {
            $this->migrationFactory?->close();
        }
    }

    protected function targetVersion(string $target): string
    {
        if ($target === '' || preg_match('/^[A-Za-z0-9_\\\\-]+$/', $target) !== 1) {
            throw new RuntimeException('Target version must be a valid migration version or "0".');
        }

        return $target;
    }

    /** @return int Symfony command exit status. */
    protected function previewMigrations(
        DependencyFactory $factory,
        string $versionAlias,
        ?string $sqlPath,
        OutputInterface $output,
        string $expectedDirection = 'up',
    ): int {
        try {
            $version = $factory->getVersionAliasResolver()->resolveVersionAlias($versionAlias);
        } catch (NoMigrationsToExecute) {
            $output->writeln('<info>No migrations are registered.</info>');

            return self::SUCCESS;
        }

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
        if (count($plan) === 0) {
            $output->writeln('<info>No pending migrations.</info>');

            return self::SUCCESS;
        }
        if ($plan->getDirection() !== $expectedDirection) {
            $output->writeln('<error>The target does not produce a '.$expectedDirection.' migration plan.</error>');

            return self::FAILURE;
        }

        $queries = $factory->getMigrator()->migrate($plan, (new MigratorConfiguration)->setDryRun(true));
        if ($sqlPath !== null) {
            if (! $factory->getQueryWriter()->write($sqlPath, $plan->getDirection(), $queries)) {
                $output->writeln('<error>Migration SQL could not be exported.</error>');

                return self::FAILURE;
            }
            $output->writeln('<info>Migration SQL exported.</info>');
        } else {
            foreach ($queries as $version => $statements) {
                $output->writeln('<comment>'.$version.'</comment>');
                foreach ($statements as $statement) {
                    $output->writeln((string) $statement.';');
                }
            }
            $output->writeln('<comment>Dry run; database and migration history were not changed.</comment>');
        }

        return self::SUCCESS;
    }
}
