<?php

namespace Tusk\Security\Contract;

interface GuardInterface
{
    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool;

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?UserInterface;

    /**
     * Set the current user.
     */
    public function setUser(UserInterface $user): void;
}
