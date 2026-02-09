<?php

namespace Tusk\Contracts\Container;

interface ContainerInterface
{
    /**
     * Resolves a service from the container.
     */
    public function get(string $id): object;

    /**
     * Checks if a service exists in the container.
     */
    public function has(string $id): bool;

    /**
     * Executes lifecycle hooks for all registered services.
     * 
     * @param string $attributeClass The FQCN of the attribute (e.g., Tusk\Core\Attributes\OnStart)
     */
    public function runHooks(string $attributeClass): void;

    /**
     * Resets all services within a specific scope.
     */
    public function resetScope(string $scope): void;
}
