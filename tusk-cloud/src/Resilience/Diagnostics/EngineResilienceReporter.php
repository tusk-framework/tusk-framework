<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Diagnostics;

use Closure;
use Throwable;
use Tusk\Cloud\Resilience\SystemClock;
use Tusk\Contracts\Observability\WorkerLifecycleCheckpointInterface;

final class EngineResilienceReporter implements WorkerLifecycleCheckpointInterface
{
    private ?string $workerId = null;

    private ?int $processId = null;

    private int $sequence = 0;

    private readonly ?string $endpoint;

    private readonly ?Closure $transport;

    private readonly Closure $clock;

    private ?int $lastAttemptAt = null;

    public function __construct(
        private readonly ResilienceDiagnosticsRegistry $registry,
        ?string $url,
        private readonly ?string $token,
        ?Closure $transport = null,
        ?Closure $clock = null,
    ) {
        $port = is_string($url) && preg_match('/\Ahttp:\/\/127\.0\.0\.1:([0-9]{1,5})\z/D', $url, $matches) === 1
            ? (int) $matches[1] : 0;
        $this->endpoint = $port >= 1 && $port <= 65535 && is_string($token) && $token !== ''
            && preg_match('/\A[\x21-\x7e]+\z/D', $token) === 1
            ? $url.'/internal/v1/resilience/snapshot' : null;
        $this->transport = $transport;
        $this->clock = $clock ?? Closure::fromCallable([new SystemClock, 'nowMilliseconds']);
    }

    public static function fromEnvironment(ResilienceDiagnosticsRegistry $registry, ?Closure $transport = null): self
    {
        $url = getenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL');
        $token = getenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN');

        return new self($registry, is_string($url) ? $url : null, is_string($token) ? $token : null, $transport);
    }

    public function checkpoint(string $boundary): void
    {
        if ($boundary === 'worker_started' || $boundary === 'worker_stopped') {
            $this->report();

            return;
        }

        if ($boundary !== 'request_finished' && $boundary !== 'job_finished') {
            return;
        }

        try {
            $now = ($this->clock)();
            if ($this->lastAttemptAt === null || $now - $this->lastAttemptAt >= 15_000) {
                $this->report($now);
            }
        } catch (Throwable) {
            // A diagnostic clock failure cannot affect the lifecycle operation.
        }
    }

    public function reportTransition(): void
    {
        $this->report();
    }

    private function report(?int $now = null): void
    {
        if ($this->endpoint === null) {
            return;
        }

        try {
            $snapshot = $this->registry->snapshot();
            if (! $snapshot->reportable() || count($snapshot->policies()) > 256 || count($snapshot->circuits()) > 256) {
                return;
            }

            $this->lastAttemptAt = $now ?? ($this->clock)();
            $processId = getmypid();
            if ($this->workerId === null || $this->processId !== $processId) {
                $this->workerId = bin2hex(random_bytes(16));
                $this->processId = $processId;
                $this->sequence = 0;
            }

            $payload = json_encode([
                'schema_version' => 'v1',
                'worker_id' => $this->workerId,
                'sequence' => ++$this->sequence,
                ...$snapshot->toArray(),
            ], JSON_THROW_ON_ERROR);
            if (strlen($payload) > 65_536) {
                return;
            }

            if ($this->transport !== null) {
                ($this->transport)($this->endpoint, $this->token, $payload, 0.05);
            } else {
                self::send($this->endpoint, $this->token, $payload);
            }
        } catch (Throwable) {
            // Reporting has no influence on requests, jobs, or policies.
        }
    }

    private static function send(string $endpoint, string $token, string $payload): void
    {
        if (! extension_loaded('curl')) {
            return;
        }

        $handle = curl_init($endpoint);
        if ($handle === false) {
            return;
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT_MS => 50,
                CURLOPT_CONNECTTIMEOUT_MS => 50,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
            ]);
            curl_exec($handle);
        } finally {
            curl_close($handle);
        }
    }
}
