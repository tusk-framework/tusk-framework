<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use InvalidArgumentException;

final readonly class ObservabilityConfiguration
{
    /**
     * @param  array<string, scalar>  $resource
     */
    private function __construct(
        private bool $enabled,
        private string $serviceName,
        private string $exporter,
        private ?string $otlpEndpoint,
        private float $sampleRatio,
        private array $resource,
        private bool $diagnosticsEnabled,
    ) {}

    public static function fromArray(array $config): self
    {
        $enabled = self::boolean($config['enabled'] ?? false, 'enabled');
        $serviceName = trim((string) ($config['service_name'] ?? 'tusk-application'));

        if ($serviceName === '') {
            throw new InvalidArgumentException('Observability service_name must not be empty.');
        }

        $exporter = strtolower(trim((string) ($config['exporter'] ?? 'none')));

        if (! in_array($exporter, ['none', 'otlp'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported observability exporter "%s".', $exporter));
        }

        $otlpEndpoint = null;
        if (isset($config['otlp'])) {
            if (! is_array($config['otlp'])) {
                throw new InvalidArgumentException('Observability otlp configuration must be an array.');
            }

            $otlpEndpoint = $config['otlp']['endpoint'] ?? null;
            if ($otlpEndpoint !== null && ! is_string($otlpEndpoint)) {
                throw new InvalidArgumentException('Observability otlp.endpoint must be a string.');
            }
        }

        if ($enabled && $exporter === 'otlp') {
            self::validateEndpoint($otlpEndpoint);
        }

        $sampleRatio = $config['sample_ratio'] ?? 1.0;
        if (! is_int($sampleRatio) && ! is_float($sampleRatio)) {
            throw new InvalidArgumentException('Observability sample_ratio must be numeric.');
        }

        $sampleRatio = (float) $sampleRatio;
        if ($sampleRatio < 0.0 || $sampleRatio > 1.0) {
            throw new InvalidArgumentException('Observability sample_ratio must be between 0.0 and 1.0.');
        }

        $resource = $config['resource'] ?? [];
        if (! is_array($resource)) {
            throw new InvalidArgumentException('Observability resource must be an array.');
        }

        foreach ($resource as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value))) {
                throw new InvalidArgumentException('Observability resource values must be scalar and keys must be strings.');
            }
        }

        return new self(
            enabled: $enabled,
            serviceName: $serviceName,
            exporter: $exporter,
            otlpEndpoint: $otlpEndpoint,
            sampleRatio: $sampleRatio,
            resource: $resource,
            diagnosticsEnabled: self::boolean($config['diagnostics'] ?? true, 'diagnostics'),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function exporter(): string
    {
        return $this->exporter;
    }

    public function otlpEndpoint(): ?string
    {
        return $this->otlpEndpoint;
    }

    public function sampleRatio(): float
    {
        return $this->sampleRatio;
    }

    /**
     * @return array<string, scalar>
     */
    public function resource(): array
    {
        return $this->resource;
    }

    public function diagnosticsEnabled(): bool
    {
        return $this->diagnosticsEnabled;
    }

    private static function validateEndpoint(?string $endpoint): void
    {
        if ($endpoint === null || trim($endpoint) === '') {
            throw new InvalidArgumentException('Observability otlp.endpoint is required when the OTLP exporter is enabled.');
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('Observability otlp.endpoint must be an absolute HTTP(S) URL.');
        }
    }

    private static function boolean(mixed $value, string $name): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Observability %s must be a boolean.', $name));
        }

        return $value;
    }
}
