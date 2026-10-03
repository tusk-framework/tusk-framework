<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface LockInterface
{
    public function acquire(string $name, ?int $ttlSeconds = null): bool;

    public function release(string $name): void;

    public function withLock(string $name, callable $handler, ?int $ttlSeconds = null): mixed;
}
