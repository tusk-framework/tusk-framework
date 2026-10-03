<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface MetricsInterface
{
    /**
     * @param array<string, scalar> $labels
     */
    public function increment(string $name, int $value = 1, array $labels = []): void;

    /**
     * @param array<string, scalar> $labels
     */
    public function decrement(string $name, int $value = 1, array $labels = []): void;

    /**
     * @param array<string, scalar> $labels
     */
    public function set(string $name, int|float $value, array $labels = []): void;

    /**
     * @param array<string, scalar> $labels
     */
    public function observe(string $name, int|float $value, array $labels = []): void;

    /**
     * @param array<string, scalar> $labels
     */
    public function declare(string $name, array $labels = []): void;

    public function unregister(string $name): void;
}
