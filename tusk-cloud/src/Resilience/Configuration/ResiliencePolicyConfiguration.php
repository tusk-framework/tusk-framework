<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Configuration;

use InvalidArgumentException;

final readonly class ResiliencePolicyConfiguration
{
    private const SECTIONS = [
        'retry' => 'retry',
        'circuit_breaker' => 'circuitBreaker',
        'bulkhead' => 'bulkhead',
        'rate_limit' => 'rateLimit',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    private function __construct(
        private string $name,
        private array $sections,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(string $name, array $values): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Configuration path resilience.policies.<name> must be a non-empty string.');
        }

        $sections = [];
        foreach ($values as $section => $configuration) {
            $path = sprintf('resilience.policies.%s.%s', $name, (string) $section);
            if (! is_string($section) || ! isset(self::SECTIONS[$section])) {
                throw new InvalidArgumentException(sprintf('Unknown configuration key at %s.', $path));
            }

            if (! is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
                throw new InvalidArgumentException(sprintf('Configuration at %s must be a named map.', $path));
            }

            $sections[$section] = $configuration;
        }

        return new self($name, $sections);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed>|null */
    public function retry(): ?array
    {
        return $this->sections['retry'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function circuitBreaker(): ?array
    {
        return $this->sections['circuit_breaker'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function bulkhead(): ?array
    {
        return $this->sections['bulkhead'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function rateLimit(): ?array
    {
        return $this->sections['rate_limit'] ?? null;
    }
}
