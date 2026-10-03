<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface KeyValueStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    public function clear(): void;
}
