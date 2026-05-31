<?php

namespace Tusk\Core\Cache;

use Psr\SimpleCache\CacheInterface;
use Tusk\Contracts\Attributes\Service;
use DateInterval;

#[Service(scope: 'singleton')]
class ArrayCache implements CacheInterface
{
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        $item = $this->data[$key];
        
        if ($item['expires_at'] !== null && microtime(true) > $item['expires_at']) {
            $this->delete($key);
            return $default;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $expiresAt = $this->calculateExpiration($ttl);

        $this->data[$key] = [
            'value'      => $value,
            'expires_at' => $expiresAt,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        if (! array_key_exists($key, $this->data)) {
            return false;
        }

        $item = $this->data[$key];
        if ($item['expires_at'] !== null && microtime(true) > $item['expires_at']) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    private function calculateExpiration(DateInterval|int|null $ttl): ?float
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return microtime(true) + $ttl;
        }

        $now = new \DateTimeImmutable();
        $expires = $now->add($ttl);
        
        return (float) $expires->format('U.u');
    }
}
