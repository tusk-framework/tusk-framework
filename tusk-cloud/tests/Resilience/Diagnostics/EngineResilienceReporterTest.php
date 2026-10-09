<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Diagnostics;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;
use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Cloud\Resilience\InMemoryStateStore;

final class EngineResilienceReporterTest extends TestCase
{
    public function test_missing_partial_and_non_loopback_configuration_make_no_attempt(): void
    {
        $attempts = [];
        $send = static function (...$arguments) use (&$attempts): void {
            $attempts[] = $arguments;
        };
        foreach ([
            [null, null], ['http://127.0.0.1:8080', null], [null, 'token'],
            ['https://127.0.0.1:8080', 'token'], ['http://localhost:8080', 'token'],
            ['http://127.0.0.2:8080', 'token'], ['http://127.0.0.1:8080/path', 'token'],
            ['http://127.0.0.1:8080?x=1', 'token'], ['http://user@127.0.0.1:8080', 'token'],
            ['http://127.0.0.1:8080', "token\r\nInjected: true"],
        ] as [$url, $token]) {
            (new EngineResilienceReporter(new ResilienceDiagnosticsRegistry(new InMemoryStateStore), $url, $token, Closure::fromCallable($send)))->checkpoint('worker_started');
        }

        self::assertSame([], $attempts);
    }

    public function test_payload_is_sanitized_bounded_and_sequence_increases_for_one_worker(): void
    {
        $registry = $this->registryWithPolicy('payments', [
            'retry' => ['max_attempts' => 3, 'retry_on' => [RuntimeException::class]],
            'circuit_breaker' => ['failure_threshold' => 1],
        ]);
        $attempts = [];
        $reporter = new EngineResilienceReporter($registry, 'http://127.0.0.1:8765', 'private-token', static function (string $url, string $token, string $body, float $timeout) use (&$attempts): void {
            $attempts[] = [$url, $token, $body, $timeout];
        });

        $reporter->checkpoint('worker_started');
        $reporter->reportTransition();

        self::assertCount(2, $attempts);
        self::assertSame('http://127.0.0.1:8765/internal/v1/resilience/snapshot', $attempts[0][0]);
        self::assertSame('private-token', $attempts[0][1]);
        self::assertSame(0.05, $attempts[0][3]);
        $first = json_decode($attempts[0][2], true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($attempts[1][2], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('v1', $first['schema_version']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $first['worker_id']);
        self::assertSame($first['worker_id'], $second['worker_id']);
        self::assertSame($first['sequence'] + 1, $second['sequence']);
        self::assertSame([['name' => 'payments', 'features' => ['retry', 'circuit_breaker']]], $first['policies']);
        self::assertSame([['name' => 'payments', 'state' => 'unknown']], $first['circuits']);
        self::assertLessThanOrEqual(65536, strlen($attempts[0][2]));
        self::assertStringNotContainsString('RuntimeException', $attempts[0][2]);
        self::assertStringNotContainsString('max_attempts', $attempts[0][2]);
    }

    public function test_heartbeat_is_coalesced_across_request_and_job_boundaries(): void
    {
        $now = 0;
        $attempts = 0;
        $reporter = new EngineResilienceReporter(
            new ResilienceDiagnosticsRegistry(new InMemoryStateStore),
            'http://127.0.0.1:8765',
            'token',
            static function () use (&$attempts): void {
                $attempts++;
            },
            static function () use (&$now): int {
                return $now;
            },
        );

        $reporter->checkpoint('worker_started');
        $now = 14_999;
        $reporter->checkpoint('request_finished');
        $reporter->checkpoint('job_finished');
        self::assertSame(1, $attempts);
        $now = 15_000;
        $reporter->checkpoint('request_finished');
        $reporter->checkpoint('job_finished');
        self::assertSame(2, $attempts);
        $reporter->checkpoint('worker_stopped');
        self::assertSame(3, $attempts);
    }

    public function test_transport_failure_and_invalid_diagnostics_never_escape_or_send_partial_payload(): void
    {
        $registry = $this->registryWithPolicy('good', ['retry' => []]);
        $registry->register(ResilienceConfigurationLoader::load(['policies' => ['bad/name' => ['retry' => []]]])->policy('bad/name'));
        $attempts = 0;
        $reporter = new EngineResilienceReporter($registry, 'http://127.0.0.1:8765', 'token', static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('transport failed');
        });

        $reporter->checkpoint('worker_started');
        self::assertSame(0, $attempts);
        $valid = new EngineResilienceReporter($this->registryWithPolicy('good', ['retry' => []]), 'http://127.0.0.1:8765', 'token', static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('transport failed');
        });
        $valid->checkpoint('worker_started');
        self::assertSame(1, $attempts);
    }

    public function test_environment_pair_controls_reporting(): void
    {
        $oldUrl = getenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL');
        $oldToken = getenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN');
        $attempts = 0;
        $transport = static function () use (&$attempts): void {
            $attempts++;
        };
        try {
            putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL=http://127.0.0.1:8765');
            putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN');
            EngineResilienceReporter::fromEnvironment(new ResilienceDiagnosticsRegistry(new InMemoryStateStore), Closure::fromCallable($transport))->checkpoint('worker_started');
            self::assertSame(0, $attempts);

            putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN=token');
            EngineResilienceReporter::fromEnvironment(new ResilienceDiagnosticsRegistry(new InMemoryStateStore), Closure::fromCallable($transport))->checkpoint('worker_started');
            self::assertSame(1, $attempts);
        } finally {
            $oldUrl === false ? putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL') : putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_URL='.$oldUrl);
            $oldToken === false ? putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN') : putenv('TUSK_ENGINE_RESILIENCE_DIAGNOSTICS_TOKEN='.$oldToken);
        }
    }

    public function test_oversized_snapshot_and_entry_count_are_not_published(): void
    {
        $registry = new ResilienceDiagnosticsRegistry(new InMemoryStateStore);
        for ($i = 0; $i < 256; $i++) {
            $name = str_pad('p'.$i, 128, 'x');
            $registry->register(ResilienceConfigurationLoader::load(['policies' => [$name => ['circuit_breaker' => []]]])->policy($name));
        }
        $attempts = 0;
        $transport = static function () use (&$attempts): void {
            $attempts++;
        };
        (new EngineResilienceReporter($registry, 'http://127.0.0.1:8765', 'token', Closure::fromCallable($transport)))->checkpoint('worker_started');
        self::assertSame(0, $attempts);

        $registry->register(ResilienceConfigurationLoader::load(['policies' => ['extra' => ['retry' => []]]])->policy('extra'));
        self::assertFalse($registry->snapshot()->reportable());
    }

    /** @param array<string, array<string, mixed>> $sections */
    private function registryWithPolicy(string $name, array $sections): ResilienceDiagnosticsRegistry
    {
        $registry = new ResilienceDiagnosticsRegistry(new InMemoryStateStore);
        $registry->register(ResilienceConfigurationLoader::load(['policies' => [$name => $sections]])->policy($name));

        return $registry;
    }
}
