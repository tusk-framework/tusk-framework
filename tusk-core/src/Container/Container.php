<?php

namespace Tusk\Core\Container;

use ReflectionClass;
use ReflectionException;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Contracts\Attributes\Service;
use Exception;
use RuntimeException;

class Container implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $scopes = [];

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new RuntimeException("Service not found: {$id}");
        }

        return $this->resolve($this->definitions[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->definitions[$id]);
    }

    /**
     * Registers a class as a service.
     */
    public function register(string $className): void
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Failed to reflect class {$className}: " . $e->getMessage(), 0, $e);
        }

        $attributes = $reflection->getAttributes(Service::class);

        if (empty($attributes)) {
            return;
        }

        /** @var Service $serviceAttr */
        $serviceAttr = $attributes[0]->newInstance();
        $scope = $serviceAttr->scope;

        $this->definitions[$className] = $className;
        $this->scopes[$className] = $scope;

        // Also register by interface if applicable
        foreach ($reflection->getInterfaceNames() as $interface) {
            $this->definitions[$interface] = $className;
        }
    }

    /**
     * Resets all services within a specific scope.
     * Useful for clearing 'worker' scoped services after a fork.
     */
    public function resetScope(string $scope): void
    {
        foreach ($this->scopes as $className => $serviceScope) {
            if ($serviceScope === $scope) {
                unset($this->instances[$className]);

                // Also unset interface aliases
                try {
                    $reflection = new ReflectionClass($className);
                    foreach ($reflection->getInterfaceNames() as $interface) {
                        unset($this->instances[$interface]);
                    }
                } catch (ReflectionException $e) {
                    // Class should exist if instance existed, but suppress just in case
                }
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

        if (null === $constructor) {
            $instance = new $className();
        } else {
            $parameters = $constructor->getParameters();
            $dependencies = [];

            foreach ($parameters as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
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

            // Update interface mappings to point to the instance
            foreach ($reflection->getInterfaceNames() as $interface) {
                $this->instances[$interface] = $instance;
            }
        }

        return $instance;
    }

    public function runHooks(string $attributeClass): void
    {
        foreach ($this->instances as $instance) {
            $reflection = new ReflectionClass($instance);
            foreach ($reflection->getMethods() as $method) {
                if (!empty($method->getAttributes($attributeClass))) {
                    $method->invoke($instance);
                }
            }
        }
    }
}
