<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface CapabilityRegistryInterface
{
    /**
     * Determines whether a capability has a provider registered.
     */
    public function has(string $name): bool;

    /**
     * Resolves a capability for the current runtime scope.
     *
     * @throws CapabilityUnavailableException
     */
    public function get(string $name): object;
}
