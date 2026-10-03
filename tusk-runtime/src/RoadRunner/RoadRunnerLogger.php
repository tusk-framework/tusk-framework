<?php

declare(strict_types=1);

namespace Tusk\Runtime\RoadRunner;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RoadRunner\Logger\Logger;

final class RoadRunnerLogger implements LoggerInterface
{
    public function __construct(private readonly Logger $logger) {}

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->logger->error($message, $this->normalizeContext($context));
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->logger->error($message, $this->normalizeContext($context));
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->logger->error($message, $this->normalizeContext($context));
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->logger->error($message, $this->normalizeContext($context));
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->logger->warning($message, $this->normalizeContext($context));
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->logger->info($message, $this->normalizeContext($context));
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->logger->info($message, $this->normalizeContext($context));
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->logger->debug($message, $this->normalizeContext($context));
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $context = $this->normalizeContext($context);

        match (strtolower((string) $level)) {
            'emergency', 'alert', 'critical', 'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'notice', 'info' => $this->logger->info($message, $context),
            'debug' => $this->logger->debug($message, $context),
            default => throw new InvalidArgumentException(sprintf('Unknown log level "%s".', (string) $level)),
        };
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function normalizeContext(array $context): array
    {
        ksort($context);

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->normalizeContext($value);
            }
        }

        return $context;
    }
}
