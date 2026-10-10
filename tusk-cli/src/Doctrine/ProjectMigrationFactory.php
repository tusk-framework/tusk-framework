<?php

declare(strict_types=1);

namespace Tusk\Cli\Doctrine;

use Doctrine\Migrations\DependencyFactory;

/** Project-root composition shared by every first-party migration command. */
final class ProjectMigrationFactory
{
    private ?DependencyFactory $dependencyFactory = null;

    private ?MigrationDependencyFactoryFactory $factory = null;

    public function __construct(private readonly string $projectRoot) {}

    public function configuration(): MigrationConfiguration
    {
        return MigrationConfiguration::load($this->projectRoot);
    }

    public function create(): DependencyFactory
    {
        if ($this->dependencyFactory === null) {
            $this->factory = new MigrationDependencyFactoryFactory($this->configuration());
            $this->dependencyFactory = $this->factory->create();
        }

        return $this->dependencyFactory;
    }

    public function close(): void
    {
        try {
            $this->factory?->close();
        } catch (\Throwable) {
            // Closing a lazy, never-connected connection must not initialize it or mask the command result.
        }
    }
}
