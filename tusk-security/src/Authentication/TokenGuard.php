<?php

namespace Tusk\Security\Authentication;

use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Contract\UserInterface;
use Tusk\Security\Contract\UserProviderInterface;
use Tusk\Web\Http\Request;

class TokenGuard implements GuardInterface
{
    private ?UserInterface $user = null;

    public function __construct(
        private UserProviderInterface $provider,
        private Request $request,
        private string $inputKey = 'api_token',
        private string $storageKey = 'api_token'
    ) {
    }

    public function check(): bool
    {
        return !is_null($this->user());
    }

    public function user(): ?UserInterface
    {
        if (!is_null($this->user)) {
            return $this->user;
        }

        $token = $this->getTokenForRequest();

        if (!empty($token)) {
            $this->user = $this->provider->loadByIdentifier($token);
        }

        return $this->user;
    }

    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }

    private function getTokenForRequest(): ?string
    {
        // Try to get token from Authorization header (Bearer)
        $header = $this->request->headers->get('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Try query param or input
        return $this->request->get($this->inputKey);
    }
}
