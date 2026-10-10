<?php

declare(strict_types=1);

namespace Tusk\Cli\Command;

use Closure;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class FrameworkCommandLoader implements CommandLoaderInterface
{
    /** @param array<string, array{class: class-string, description?: string}> $applicationCommands */
    public function __construct(
        private readonly FrameworkCommandCatalog $frameworkCommands,
        private readonly array $applicationCommands = [],
        private readonly ?Closure $resolveApplicationCommand = null,
    ) {
        $collisions = array_values(array_intersect($frameworkCommands->getNames(), array_keys($applicationCommands)));
        if ($collisions !== []) {
            sort($collisions);
            throw new LogicException(sprintf(
                'Application command name(s) are reserved by the Tusk Framework: %s.',
                implode(', ', $collisions),
            ));
        }
    }

    public function get(string $name): Command
    {
        if ($this->frameworkCommands->has($name)) {
            return $this->frameworkCommands->get($name);
        }

        if (isset($this->applicationCommands[$name]) && $this->resolveApplicationCommand !== null) {
            return ($this->resolveApplicationCommand)($this->applicationCommands[$name]['class']);
        }

        throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
    }

    public function has(string $name): bool
    {
        return $this->frameworkCommands->has($name) || isset($this->applicationCommands[$name]);
    }

    public function getNames(): array
    {
        return array_values(array_unique(array_merge(
            $this->frameworkCommands->getNames(),
            array_keys($this->applicationCommands),
        )));
    }
}
