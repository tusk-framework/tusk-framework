<?php

namespace Tusk\Security\User;

use Tusk\Security\Contract\UserProviderInterface;
use Tusk\Security\Contract\UserInterface;

class InMemoryUserProvider implements UserProviderInterface
{
    /**
     * @var array<string, UserInterface>
     */
    private array $users = [];

    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->addUser($user);
        }
    }

    public function addUser(UserInterface $user): void
    {
        $this->users[(string) $user->getIdentifier()] = $user;
    }

    public function loadByIdentifier(string|int $identifier): ?UserInterface
    {
        return $this->users[(string) $identifier] ?? null;
    }
}
