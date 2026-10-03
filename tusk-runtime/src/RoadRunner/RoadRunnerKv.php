<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Spiral\RoadRunner\KeyValue\StorageInterface;
use Tusk\Contracts\Runtime\Capabilities\KeyValueStoreInterface;

final class RoadRunnerKv implements KeyValueStoreInterface
{
    public function __construct(private readonly StorageInterface $storage) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->storage->set($key, $value, $ttlSeconds);
    }

    public function has(string $key): bool
    {
        return $this->storage->has($key);
    }

    public function delete(string $key): void
    {
        $this->storage->delete($key);
    }

    public function clear(): void
    {
        $this->storage->clear();
    }
}
