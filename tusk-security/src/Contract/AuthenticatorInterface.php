<?php

namespace Tusk\Security\Contract;

use Tusk\Web\Http\Request;

interface AuthenticatorInterface
{
    /**
     * Attempt to authenticate the user based on the request.
     */
    public function authenticate(Request $request): ?UserInterface;
}
