<?php

declare(strict_types=1);

namespace Tusk\Runtime;

use InvalidArgumentException;
use Tusk\Config\Env;
use Tusk\Runtime\Jobs\JobRetryConfiguration;
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
        private readonly JobRetryConfiguration $jobRetry,
        private readonly string $executionMode,
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

        if ($adapter !== 'roadrunner') {
            throw new InvalidArgumentException(sprintf(
                'Unsupported runtime adapter "%s". Supported adapters: roadrunner.',
                $adapter,
            ));
        }

        $configuredMode = Env::get('RR_MODE', $runtime['mode'] ?? 'http');
        if (! is_string($configuredMode)) {
            throw new InvalidArgumentException('RoadRunner execution mode must be a string.');
        }
        $mode = strtolower(trim($configuredMode));
        if (! in_array($mode, ['http', 'jobs'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported RoadRunner execution mode "%s". Supported RoadRunner modes: http, jobs.',
                $mode,
            ));
        }

        $modules = $runtime['modules'] ?? ['http'];

        $jobs = $runtime['jobs'] ?? [];
        if (! is_array($jobs)) {
            throw new InvalidArgumentException('The runtime jobs configuration must be an array.');
        }
        $retry = $jobs['retry'] ?? [];
        if (! is_array($retry)) {
            throw new InvalidArgumentException('The runtime jobs retry configuration must be an array.');
        }

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

        return new self($adapter, $normalized, ObservabilityConfiguration::fromArray($observability), JobRetryConfiguration::fromArray($retry), $mode);
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

    public function jobRetry(): JobRetryConfiguration
    {
        return $this->jobRetry;
    }

    public function executionMode(): string
    {
        return $this->executionMode;
    }
}
