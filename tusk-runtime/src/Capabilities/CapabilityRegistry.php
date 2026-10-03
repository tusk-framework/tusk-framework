<?php

declare(strict_types=1);

namespace Tusk\Runtime\Capabilities;

use Tusk\Contracts\Runtime\Capabilities\CapabilityProviderInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityRegistryInterface;
use Tusk\Contracts\Runtime\Capabilities\CapabilityUnavailableException;

final class CapabilityRegistry implements CapabilityRegistryInterface
{
    /** @var list<CapabilityProviderInterface> */
    private array $providers;

    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * @param iterable<CapabilityProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = [];

        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->resolved)) {
            return true;
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($name)) {
                return true;
            }
        }

        return false;
    }

    public function get(string $name): object
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        foreach ($this->providers as $provider) {
            if (! $provider->supports($name)) {
                continue;
            }

            return $this->resolved[$name] = $provider->provide($name);
        }

        throw new CapabilityUnavailableException($name);
    }
}
