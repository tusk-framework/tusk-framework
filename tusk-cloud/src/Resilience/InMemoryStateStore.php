<?php

namespace Tusk\Cloud\Resilience;

use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

class InMemoryStateStore implements StateStoreInterface
{
    private array $storage = [];

    public function get(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, array $data, ?float $ttl = null): void
    {
        $this->storage[$key] = $data;
    }
}
