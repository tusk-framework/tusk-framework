<?php

declare(strict_types=1);

namespace Tusk\Runtime;

use InvalidArgumentException;
use Tusk\Runtime\Observability\ObservabilityConfiguration;

final class RuntimeConfiguration
{
    /** @var list<string> */
    private const MODULES = [
        'http',
        'grpc',
        'capabilities.jobs',
        'capabilities.kv',
        'capabilities.lock',
        'capabilities.metrics',
        'capabilities.logger',
    ];

    /**
     * @param  list<string>  $modules
     */
    private function __construct(
        private readonly string $runtimeAdapter,
        private readonly array $modules,
        private readonly ObservabilityConfiguration $observability,
    ) {}

    public static function fromArray(array $config): self
    {
        $runtime = $config['runtime'] ?? [];

        if (! is_array($runtime)) {
            throw new InvalidArgumentException('The runtime configuration must be an array.');
        }

        $observability = $config['observability'] ?? [];
        if (! is_array($observability)) {
            throw new InvalidArgumentException('The observability configuration must be an array.');
        }

        $adapter = strtolower(trim((string) ($runtime['adapter'] ?? 'roadrunner')));
        $adapter = $adapter === 'rr' ? 'roadrunner' : $adapter;

        if (! in_array($adapter, ['roadrunner', 'native'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported runtime adapter "%s".', $adapter));
        }

        $modules = $runtime['modules'] ?? ['http'];

        if (! is_array($modules)) {
            throw new InvalidArgumentException('The runtime modules configuration must be an array.');
        }

        $normalized = [];

        foreach ($modules as $module) {
            if (! is_string($module)) {
                throw new InvalidArgumentException('Runtime module names must be strings.');
            }

            $module = strtolower(trim($module));

            if (! in_array($module, self::MODULES, true)) {
                throw new InvalidArgumentException(sprintf('Unknown runtime module "%s".', $module));
            }

            if (! in_array($module, $normalized, true)) {
                $normalized[] = $module;
            }
        }

        if ($adapter === 'native') {
            foreach ($normalized as $module) {
                if ($module !== 'http') {
                    throw new InvalidArgumentException(sprintf(
                        'Runtime module "%s" requires the RoadRunner adapter.',
                        $module,
                    ));
                }
            }
        }

        return new self($adapter, $normalized, ObservabilityConfiguration::fromArray($observability));
    }

    public function adapter(): string
    {
        return $this->runtimeAdapter;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    public function observability(): ObservabilityConfiguration
    {
        return $this->observability;
    }
}
