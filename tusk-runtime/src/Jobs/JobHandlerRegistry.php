<?php

namespace Tusk\Runtime\Jobs;

use InvalidArgumentException;
use ReflectionClass;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;

final class JobHandlerRegistry
{
    /** @var array<string, class-string<JobHandlerInterface>> */
    private array $handlers = [];

    /** @param array<string, class-string> $handlers */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $name => $class) {
            $stableName = trim($name);
            if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $stableName)) {
                throw new InvalidArgumentException('Invalid job name: '.$name);
            }
            if (isset($this->handlers[$stableName])) {
                throw new InvalidArgumentException('Duplicate job name: '.$stableName);
            }
            if (! is_a($class, JobHandlerInterface::class, true) || ! (new ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException('Job handler must be an instantiable JobHandlerInterface: '.$class);
            }
            $this->handlers[$stableName] = $class;
        }
    }

    public function handlerClass(string $name): string
    {
        return $this->handlers[$name] ?? throw new UnknownJobException('Unknown job name.');
    }

    /** @return array<string, class-string<JobHandlerInterface>> */
    public function handlers(): array
    {
        return $this->handlers;
    }
}
