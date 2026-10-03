<?php

namespace Tusk\Contracts\Cloud\Resilience;

interface StateStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function set(string $key, array $data, ?float $ttl = null): void;
}
