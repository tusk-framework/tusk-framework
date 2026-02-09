<?php

namespace Tusk\Security\User;

use Tusk\Security\Contract\UserInterface;

class InMemoryUser implements UserInterface
{
    public function __construct(
        private string|int $identifier,
        private array $roles = []
    ) {
    }

    public function getIdentifier(): string|int
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
