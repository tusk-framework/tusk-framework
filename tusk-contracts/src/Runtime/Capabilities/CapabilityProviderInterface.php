<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface CapabilityProviderInterface
{
    /**
     * Determines whether this provider can create the named capability.
     */
    public function supports(string $name): bool;

    /**
     * Creates the named capability for the current runtime scope.
     */
    public function provide(string $name): object;
}
