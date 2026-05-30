<?php

namespace Tusk\Security\Authentication;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tusk\Config\Env;
use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Contract\UserInterface;
use Tusk\Security\Contract\UserProviderInterface;
use Tusk\Web\Http\Request;

class JwtGuard implements GuardInterface
{
    private ?UserInterface $user = null;

    public function __construct(
        private UserProviderInterface $provider,
        private Request $request,
        private string $algo = 'HS256'
    ) {}

    public function check(): bool
    {
        return ! is_null($this->user());
    }

    public function user(): ?UserInterface
    {
        if (! is_null($this->user)) {
            return $this->user;
        }

        $token = $this->getTokenForRequest();

        if (empty($token)) {
            return null;
        }

        try {
            $secret = Env::get('JWT_SECRET', 'default_secret');
            $decoded = JWT::decode($token, new Key($secret, $this->algo));

            if (isset($decoded->sub)) {
                $this->user = $this->provider->loadByIdentifier((string) $decoded->sub);
            }
        } catch (\Exception $e) {
            // Token is invalid or expired
            return null;
        }

        return $this->user;
    }

    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }

    private function getTokenForRequest(): ?string
    {
        $header = $this->request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $this->request->get('token');
    }
}
