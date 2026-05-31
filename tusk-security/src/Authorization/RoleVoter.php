<?php

namespace Tusk\Security\Authorization;

use Tusk\Security\Contract\UserInterface;

class RoleVoter implements VoterInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'ROLE_')
    {
        $this->prefix = $prefix;
    }

    public function supports(string $attribute, mixed $subject): bool
    {
        // Only vote on attributes starting with the prefix (e.g., ROLE_ADMIN)
        return str_starts_with($attribute, $this->prefix);
    }

    public function vote(UserInterface $user, string $attribute, mixed $subject): int
    {
        // Simple check: Does the user's getRoles() array contain the attribute?
        if (in_array($attribute, $user->getRoles(), true)) {
            return self::ACCESS_GRANTED;
        }

        return self::ACCESS_DENIED;
    }
}
