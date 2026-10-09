<?php

namespace Tusk\Core\Container;

use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Tusk\Contracts\Attributes\AsJob;
use Tusk\Contracts\Attributes\OnJobEnd;
use Tusk\Contracts\Attributes\OnJobStart;
use Tusk\Contracts\Attributes\OnRequestEnd;
use Tusk\Contracts\Attributes\OnRequestStart;
use Tusk\Contracts\Attributes\OnShutdown;
use Tusk\Contracts\Attributes\OnStart;
use Tusk\Contracts\Attributes\OnWorkerStart;
use Tusk\Contracts\Attributes\OnWorkerStop;
use Tusk\Contracts\Attributes\Service;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Runtime\Jobs\JobHandlerInterface;

class Container implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $scopes = [];

    /** @var array<string, array<string, list<string>>> */
    private array $hooks = [];

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! isset($this->definitions[$id])) {
            throw new RuntimeException("Service not found: {$id}");
        }

        return $this->resolve($this->definitions[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->definitions[$id]);
    }

    /**
     * Bind an existing instance into the container.
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->hooks = array_replace_recursive($this->hooks, [$id => $this->discoverHooks(new ReflectionClass($instance))]);
    }

    /**
     * Registers a class as a service.
     */
    public function register(string $className, ?string $defaultScope = null): void
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Failed to reflect class {$className}: ".$e->getMessage(), 0, $e);
        }

        $jobAttributes = $reflection->getAttributes(AsJob::class);
        $attributes = $reflection->getAttributes(Service::class);

        if (empty($attributes) && empty($jobAttributes) && $defaultScope === null) {
            return;
        }

        if (! empty($jobAttributes) && (! $reflection->isInstantiable() || ! $reflection->implementsInterface(JobHandlerInterface::class))) {
            throw new RuntimeException('Job handler must be an instantiable JobHandlerInterface: '.$className);
        }

        $scope = ! empty($jobAttributes)
            ? 'job'
            : (! empty($attributes) ? $attributes[0]->newInstance()->scope : $defaultScope);

        $this->definitions[$className] = $className;
        $this->scopes[$className] = $scope;
        $this->hooks = array_replace_recursive($this->hooks, [$className => $this->discoverHooks($reflection)]);

        // Also register by interface if applicable
        if (empty($jobAttributes)) {
            foreach ($reflection->getInterfaceNames() as $interface) {
                $this->definitions[$interface] = $className;
            }
        }
    }

    /**
     * Set definitions and scopes manually (e.g., from a cache file).
     */
    public function setDefinitions(array $definitions, array $scopes, array $hooks = []): void
    {
        $this->definitions = $definitions;
        $this->scopes = $scopes;
        $this->hooks = $hooks;
    }

    /**
     * Exports the current definitions and scopes.
     */
    public function export(): array
    {
        return [
            'definitions' => $this->definitions,
            'scopes' => $this->scopes,
            'hooks' => $this->hooks,
        ];
    }

    /**
     * Resets all services within a specific scope.
     * Useful for clearing 'worker' scoped services after a fork.
     */
    public function resetScope(string $scope): void
    {
        foreach ($this->definitions as $id => $className) {
            $serviceScope = $this->scopes[$className] ?? 'singleton';
            if ($serviceScope === $scope) {
                unset($this->instances[$id]);
            }
        }
    }

    /**
     * Resolves a class and its dependencies recursively.
     */
    private function resolve(string $className): object
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Class not found: {$className}", 0, $e);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = new $className;
        } else {
            $parameters = $constructor->getParameters();
            $dependencies = [];

            foreach ($parameters as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    throw new RuntimeException("Cannot resolve builtin, union, or mixed type for parameter {$parameter->getName()} in {$className}");
                }

                $dependencyClassName = $type->getName();
                $dependencies[] = $this->get($dependencyClassName);
            }

            $instance = $reflection->newInstanceArgs($dependencies);
        }

        // Cache instance based on scope strategy (Singleton logic for now)
        // In v0.2, if it's 'prototype', we wouldn't cache it.
        // But for 'singleton' and 'worker', we cache it until reset.
        $scope = $this->scopes[$className] ?? 'singleton';

        if ($scope !== 'prototype') {
            $this->instances[$className] = $instance;

            // Update all registered aliases to point to the same instance.
            foreach ($this->definitions as $id => $registeredClass) {
                if ($registeredClass === $className) {
                    $this->instances[$id] = $instance;
                }
            }
        }

        return $instance;
    }

    public function runHooks(string $attributeClass): void
    {
        $event = match ($attributeClass) {
            OnStart::class => 'application.start',
            OnWorkerStart::class => 'worker.start',
            OnRequestStart::class => 'request.start',
            OnRequestEnd::class => 'request.end',
            OnJobStart::class => 'job.start',
            OnJobEnd::class => 'job.end',
            OnWorkerStop::class => 'worker.stop',
            OnShutdown::class => 'application.stop',
            default => null,
        };

        if ($event !== null) {
            $this->runLifecycleHooks($event);

            return;
        }
        $seen = [];
        foreach ($this->instances as $instance) {
            $id = spl_object_id($instance);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $reflection = new ReflectionClass($instance);
            foreach ($reflection->getMethods() as $method) {
                if (! empty($method->getAttributes($attributeClass))) {
                    $method->invoke($instance);
                }
            }
        }
    }

    public function runLifecycleHooks(string $event): void
    {
        $firstFailure = null;
        $seen = [];

        foreach ($this->hooks as $serviceClass => $events) {
            $methods = $events[$event] ?? [];
            if ($methods === []) {
                continue;
            }

            $instance = $this->get($serviceClass);
            $id = spl_object_id($instance);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            foreach ($methods as $methodName) {
                try {
                    $instance->{$methodName}();
                } catch (\Throwable $exception) {
                    $firstFailure ??= $exception;
                }
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function discoverHooks(ReflectionClass $reflection): array
    {
        $hooks = [];
        $hookAttributes = [
            OnStart::class => 'application.start',
            OnWorkerStart::class => 'worker.start',
            OnRequestStart::class => 'request.start',
            OnRequestEnd::class => 'request.end',
            OnJobStart::class => 'job.start',
            OnJobEnd::class => 'job.end',
            OnWorkerStop::class => 'worker.stop',
            OnShutdown::class => 'application.stop',
        ];

        foreach ($reflection->getMethods() as $method) {
            foreach ($hookAttributes as $attributeClass => $event) {
                if (! empty($method->getAttributes($attributeClass))) {
                    $hooks[$event] ??= [];
                    $hooks[$event][] = $method->getName();
                }
            }
        }

        return $hooks;
    }
}
