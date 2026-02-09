<?php

namespace Tusk\Security\Contract;

interface UserInterface
{
    /**
     * Returns the unique identifier for the user (e.g. username, email, ID).
     */
    public function getIdentifier(): string|int;

    /**
     * Returns the roles granted to the user.
     *
     * @return string[]
     */
    public function getRoles(): array;
}
