<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Diagnostics;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;
use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceRuntime;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class ResilienceRuntimeTest extends TestCase
{
    public function test_effective_profile_is_registered_only_after_successful_resolution(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['payments' => ['retry' => ['max_attempts' => 2]]],
            'profiles' => ['testing' => ['policies' => ['payments' => [
                'circuit_breaker' => ['failure_threshold' => 1],
                'bulkhead' => ['max_concurrent' => 2],
            ]]]],
        ], 'testing');
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry), $registry);

        self::assertSame([], $runtime->diagnostics()->policies());
        $runtime->pipeline('payments');
        self::assertSame([['name' => 'payments', 'features' => ['retry', 'circuit_breaker', 'bulkhead']]], $runtime->diagnostics()->policies());
        self::assertSame([['name' => 'payments', 'state' => 'unknown']], $runtime->diagnostics()->circuits());

        try {
            $runtime->pipeline('missing');
            self::fail('Unknown policy resolved.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $runtime->diagnostics()->policies());
        }
    }

    public function test_snapshot_is_detached_and_confirmed_store_state_changes_are_visible(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => [
            'payments' => ['circuit_breaker' => ['failure_threshold' => 1]],
        ]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry), $registry);
        $pipeline = $runtime->pipeline('payments');
        $before = $runtime->diagnostics();

        try {
            $pipeline->run(static fn (): never => throw new RuntimeException('secret failure'));
            self::fail('Expected operation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('secret failure', $exception->getMessage());
        }

        self::assertSame([['name' => 'payments', 'state' => 'unknown']], $before->circuits());
        self::assertSame([['name' => 'payments', 'state' => 'open']], $runtime->diagnostics()->circuits());
        self::assertStringNotContainsString('secret failure', json_encode($runtime->diagnostics()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_invalid_name_disables_reporting_without_changing_pipeline_execution(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => [
            'secret/path' => ['retry' => ['max_attempts' => 1]],
        ]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry), $registry);

        self::assertSame('ok', $runtime->pipeline('secret/path')->run(static fn (): string => 'ok'));
        self::assertFalse($runtime->diagnostics()->reportable());
        self::assertSame([], $runtime->diagnostics()->policies());
    }

    public function test_snapshot_excludes_settings_and_throwable_class_names(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => [
            'payments' => ['retry' => ['max_attempts' => 3, 'retry_on' => [RuntimeException::class]]],
        ]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry), $registry);
        $runtime->pipeline('payments');

        self::assertSame(['policies' => [['name' => 'payments', 'features' => ['retry']]], 'circuits' => []], $runtime->diagnostics()->toArray());
    }

    public function test_accepted_circuit_transition_reports_confirmed_state_without_affecting_failure(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => [
            'payments' => ['circuit_breaker' => ['failure_threshold' => 1]],
        ]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $payloads = [];
        $reporter = new EngineResilienceReporter($registry, 'http://127.0.0.1:8765', 'token', static function (string $url, string $token, string $body) use (&$payloads): never {
            $payloads[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            throw new RuntimeException('reporter transport failed');
        });
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry, reporter: $reporter), $registry);

        try {
            $runtime->pipeline('payments')->run(static fn (): never => throw new RuntimeException('business failure'));
            self::fail('Expected business failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('business failure', $exception->getMessage());
        }

        self::assertCount(1, $payloads);
        self::assertSame([['name' => 'payments', 'state' => 'open']], $payloads[0]['circuits']);
    }

    public function test_transition_uses_resolved_policy_even_when_operation_context_has_another_name(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => [
            'payments' => ['circuit_breaker' => ['failure_threshold' => 1]],
        ]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $payloads = [];
        $reporter = new EngineResilienceReporter($registry, 'http://127.0.0.1:8765', 'token', static function (string $url, string $token, string $body) use (&$payloads): void {
            $payloads[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        });
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry, reporter: $reporter), $registry);

        try {
            $runtime->pipeline('payments')->run(static fn (): never => throw new RuntimeException('failure'), OperationContext::create('order.charge'));
            self::fail('Expected operation failure.');
        } catch (RuntimeException) {
            self::assertCount(1, $payloads);
            self::assertSame([['name' => 'payments', 'state' => 'open']], $payloads[0]['circuits']);
        }
    }

    public function test_corrupt_store_entry_is_unknown_until_a_valid_state_is_confirmed(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => ['payments' => ['circuit_breaker' => []]]]);
        $store = new InMemoryStateStore;
        $registry = new ResilienceDiagnosticsRegistry($store);
        $runtime = new ResilienceRuntime($configuration, new ResiliencePipelineFactory(new FakeClock, $store, registry: $registry), $registry);
        $runtime->pipeline('payments');
        $store->set('cb:payments', ['state' => 'OPEN']);

        self::assertSame([['name' => 'payments', 'state' => 'unknown']], $runtime->diagnostics()->circuits());
    }
}
