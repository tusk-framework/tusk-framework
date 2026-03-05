<?php

namespace Tusk\Contracts\Cloud\Resilience;

interface StateStoreInterface
{
    public function get(string $key): ?array;
    public function set(string $key, array $data, ?float $ttl = null): void;
}
