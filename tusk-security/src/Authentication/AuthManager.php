<?php

namespace Tusk\Security\Authentication;

use Tusk\Contracts\Attributes\Service;
use Tusk\Security\Contract\GuardInterface;
use Tusk\Security\Contract\UserInterface;
use RuntimeException;

/**
 * AuthManager acts as the primary access point for authentication.
 * It is Request-Scoped to ensure user state is completely cleared
 * between continuous requests in long-lived workers (Swoole/RoadRunner).
 */
#[Service(scope: 'request')]
class AuthManager implements GuardInterface
{
    private ?UserInterface $user = null;
    
    /** @var array<string, GuardInterface> */
    private array $guards = [];
    
    private ?string $defaultGuard = null;
    
    public function addGuard(string $name, GuardInterface $guard, bool $default = false): void
    {
        $this->guards[$name] = $guard;
        if ($default || $this->defaultGuard === null) {
            $this->defaultGuard = $name;
        }
    }
    
    public function guard(?string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;
        if ($name === null || !isset($this->guards[$name])) {
            throw new RuntimeException("Guard not found: {$name}");
        }
        return $this->guards[$name];
    }
    
    public function check(): bool
    {
        if ($this->user !== null) {
            return true;
        }
        
        return $this->defaultGuard !== null && $this->guard()->check();
    }
    
    public function user(): ?UserInterface
    {
        if ($this->user !== null) {
            return $this->user;
        }
        
        if ($this->defaultGuard !== null) {
            $user = $this->guard()->user();
            if ($user !== null) {
                $this->setUser($user);
                return $user;
            }
        }
        
        return null;
    }
    
    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }
}
