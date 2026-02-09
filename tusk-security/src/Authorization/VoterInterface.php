<?php

namespace Tusk\Security\Authorization;

use Tusk\Security\Contract\UserInterface;

interface VoterInterface
{
    public const ACCESS_GRANTED = 1;
    public const ACCESS_ABSTAIN = 0;
    public const ACCESS_DENIED = -1;

    /**
     * Checks if the voter supports the given attribute and subject.
     */
    public function supports(string $attribute, mixed $subject): bool;

    /**
     * Perform the vote.
     */
    public function vote(UserInterface $user, string $attribute, mixed $subject): int;
}
