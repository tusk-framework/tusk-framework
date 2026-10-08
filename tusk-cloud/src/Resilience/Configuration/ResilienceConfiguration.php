<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Configuration;

use InvalidArgumentException;

final readonly class ResilienceConfiguration
{
    /**
     * @param  array<string, ResiliencePolicyConfiguration>  $policies
     */
    private function __construct(
        private array $policies,
    ) {}

    /**
     * @param  array<array-key, mixed>  $policies
     */
    public static function fromArray(array $policies): self
    {
        if ($policies !== [] && array_is_list($policies)) {
            throw new InvalidArgumentException('Configuration at resilience.policies must be a named map.');
        }

        $configurations = [];
        foreach ($policies as $name => $values) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Configuration at resilience.policies.<name> must use a string policy name.');
            }

            $normalizedName = trim($name);
            if (array_key_exists($normalizedName, $configurations)) {
                throw new InvalidArgumentException(sprintf('Duplicate policy name at resilience.policies.%s after trimming.', $normalizedName));
            }

            if (! is_array($values)) {
                throw new InvalidArgumentException(sprintf('Configuration at resilience.policies.%s must be a named map.', $normalizedName));
            }

            $configurations[$normalizedName] = ResiliencePolicyConfiguration::fromArray($name, $values);
        }

        return new self($configurations);
    }

    public function policy(string $name): ?ResiliencePolicyConfiguration
    {
        return $this->policies[trim($name)] ?? null;
    }

    /** @return array<string, ResiliencePolicyConfiguration> */
    public function all(): array
    {
        return $this->policies;
    }
}
