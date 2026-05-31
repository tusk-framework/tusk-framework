<?php

namespace Tusk\Contracts\Security;

interface IdentityInterface
{
    /**
     * Gets the unique identifier for the user/principal.
     *
     * @return string|int
     */
    public function getIdentifier(): string|int;

    /**
     * Checks if the identity has a specific role.
     *
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool;
}
