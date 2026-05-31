<?php

namespace Tusk\Core\Cache;

use Psr\SimpleCache\CacheInterface;
use Tusk\Contracts\Attributes\Service;
use DateInterval;

#[Service(scope: 'singleton')]
class CacheManager implements CacheInterface
{
    private array $stores = [];
    private string $defaultStore;

    public function __construct()
    {
        // By default, register the ArrayCache as the standard memory store
        $this->addStore('array', new ArrayCache());
        $this->defaultStore = 'array';
    }

    public function addStore(string $name, CacheInterface $store): void
    {
        $this->stores[$name] = $store;
    }

    public function setDefaultStore(string $name): void
    {
        if (!isset($this->stores[$name])) {
            throw new \InvalidArgumentException("Cache store [{$name}] is not defined.");
        }
        $this->defaultStore = $name;
    }

    public function store(?string $name = null): CacheInterface
    {
        $name = $name ?? $this->defaultStore;
        
        if (!isset($this->stores[$name])) {
            throw new \InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        return $this->stores[$name];
    }

    // Proxy PSR-16 methods to the default store
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store()->delete($key);
    }

    public function clear(): bool
    {
        return $this->store()->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->store()->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->store()->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }
}
