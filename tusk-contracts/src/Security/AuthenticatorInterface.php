<?php

namespace Tusk\Contracts\Security;

interface AuthenticatorInterface
{
    /**
     * Authenticates a request or credentials and returns an Identity.
     *
     * @param mixed $credentials The credentials to authenticate.
     * @return IdentityInterface|null The authenticated identity, or null if authentication fails.
     */
    public function authenticate(mixed $credentials): ?IdentityInterface;
}
