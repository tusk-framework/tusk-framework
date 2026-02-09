<?php

namespace Tusk\Security\Contract;

interface UserProviderInterface
{
    /**
     * Loads the user for the given identifier.
     *
     * @return UserInterface|null Returns null if the user is not found.
     */
    public function loadByIdentifier(string|int $identifier): ?UserInterface;
}
