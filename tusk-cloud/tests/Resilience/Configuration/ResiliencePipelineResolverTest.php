<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;
use Tusk\Cloud\Resilience\Configuration\ResiliencePipelineResolver;
use Tusk\Cloud\Resilience\DefaultFailureClassifier;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\Random\SecureRandomSource;
use Tusk\Cloud\Resilience\ResiliencePipelineBuilder;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Cloud\Resilience\Testing\FakeRandomSource;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class ResiliencePipelineResolverTest extends TestCase
{
    public function test_it_resolves_configured_backoff_types_to_existing_pipeline_behavior(): void
    {
        foreach (
            [
                ['fixed', 100, 150, 100, 1],
                ['exponential', 100, 300, 300, 2],
            ] as [$type, $base, $maximum, $expectedTime, $failuresBeforeSuccess]
        ) {
            $clock = new FakeClock;
            $factory = $this->factory($clock);
            $configuration = ResilienceConfigurationLoader::load([
                'policies' => ['payments' => ['retry' => [
                    'max_attempts' => $failuresBeforeSuccess + 1,
                    'retry_on' => [RuntimeException::class],
                    'backoff' => ['type' => $type, 'base_delay_ms' => $base, 'max_delay_ms' => $maximum],
                ]]],
            ]);
            $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $factory)->build();
            $attempts = 0;

            $result = $pipeline->run(
                static function () use (&$attempts, $failuresBeforeSuccess): string {
                    if ($attempts++ < $failuresBeforeSuccess) {
                        throw new RuntimeException('transient');
                    }

                    return 'ok';
                },
                OperationContext::create('payments', retryAllowed: true),
            );

            self::assertSame('ok', $result);
            self::assertSame($expectedTime, $clock->nowMilliseconds());
        }
    }

    public function test_it_resolves_jitter_with_injected_random_source_and_defaults_to_secure_source(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['payments' => ['retry' => [
                'max_attempts' => 2,
                'retry_on' => [RuntimeException::class],
                'backoff' => ['type' => 'decorrelated_jitter', 'base_delay_ms' => 10],
            ]]],
        ]);
        $random = new FakeRandomSource([17]);
        $clock = new FakeClock;
        $builder = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory($clock), $random);
        $attempts = 0;

        self::assertInstanceOf(ResiliencePipelineBuilder::class, $builder);
        $builder->run(static function () use (&$attempts): string {
            if ($attempts++ === 0) {
                throw new RuntimeException('transient');
            }

            return 'ok';
        }, OperationContext::create('payments', retryAllowed: true));
        self::assertSame([[10, 30]], $random->requestedRanges());

        $defaultPolicy = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory())->build();
        $retryPolicy = $this->readPrivateProperty($defaultPolicy, 'retryPolicy');
        self::assertInstanceOf(SecureRandomSource::class, $this->readPrivateProperty($retryPolicy->backoffStrategy(), 'randomSource'));
    }

    public function test_configured_failure_classifier_applies_deny_before_allow(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['payments' => ['retry' => [
                'max_attempts' => 2,
                'retry_on' => [RuntimeException::class],
                'do_not_retry_on' => [\DomainException::class],
            ]]],
        ]);
        $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory())->build();
        $classifier = $this->readPrivateProperty($pipeline, 'retryPolicy')->classifier();
        $context = OperationContext::create('payments', retryAllowed: true);

        self::assertFalse($classifier->classify(new \DomainException, $context)->isRetryable());
        self::assertTrue($classifier->classify(new RuntimeException, $context)->isRetryable());
        self::assertFalse($classifier->classify(new \LogicException, $context)->isRetryable());
    }

    public function test_missing_allow_list_delegates_to_default_classifier(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => ['payments' => ['retry' => ['max_attempts' => 2]]]]);
        $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory())->build();
        $classifier = $this->readPrivateProperty($pipeline, 'retryPolicy')->classifier();

        self::assertInstanceOf(DefaultFailureClassifier::class, $classifier);
    }

    public function test_it_resolves_existing_circuit_bulkhead_and_rate_limit_policy_objects(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => ['payments' => [
            'circuit_breaker' => ['failure_threshold' => 2, 'open_duration_ms' => 500, 'half_open_probe_limit' => 3],
            'bulkhead' => ['max_concurrent' => 4, 'max_queued' => 5],
            'rate_limit' => ['capacity' => 10, 'refill_per_second' => 2.5, 'max_wait_ms' => 200],
        ]]]);
        $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory())->build();

        $circuit = $this->readPrivateProperty($pipeline, 'circuitBreakerPolicy');
        self::assertSame(2, $circuit->failureThreshold());
        self::assertSame(500, $circuit->openDurationMilliseconds());
        self::assertSame(3, $circuit->halfOpenProbeLimit());

        $bulkhead = $this->readPrivateProperty($pipeline, 'bulkheadPolicy');
        self::assertSame(4, $bulkhead->maxConcurrent());
        self::assertSame(5, $bulkhead->maxQueued());

        $rateLimit = $this->readPrivateProperty($pipeline, 'rateLimitPolicy');
        self::assertSame(10, $rateLimit->capacity());
        self::assertSame(2.5, $rateLimit->refillPerSecond());
        self::assertSame(200, $rateLimit->maxWaitMilliseconds());
    }

    public function test_unsafe_retry_remains_disabled_unless_explicitly_enabled(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => ['payments' => ['retry' => [
            'max_attempts' => 2,
            'retry_on' => [RuntimeException::class],
        ]]]]);
        $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $this->factory())->build();
        $attempts = 0;

        try {
            $pipeline->run(static function () use (&$attempts): never {
                $attempts++;
                throw new RuntimeException('unsafe');
            }, OperationContext::create('payments'));
            self::fail('Unsafe operation unexpectedly retried and succeeded.');
        } catch (RuntimeException $exception) {
            self::assertSame('unsafe', $exception->getMessage());
        }

        self::assertSame(1, $attempts);
    }

    public function test_configured_circuit_breaker_uses_existing_failure_and_open_state_behavior(): void
    {
        $configuration = ResilienceConfigurationLoader::load(['policies' => ['payments' => [
            'circuit_breaker' => ['failure_threshold' => 1, 'open_duration_ms' => 1_000],
        ]]]);
        $factory = $this->factory();
        $pipeline = ResiliencePipelineResolver::resolve('payments', $configuration, $factory)->build();
        try {
            $pipeline->run(static fn (): never => throw new RuntimeException('failed'));
            self::fail('Expected operation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        try {
            $pipeline->run(static fn (): string => 'unexpected');
            self::fail('Open circuit admitted an operation.');
        } catch (CircuitOpenException) {
            self::assertTrue(true);
        }
    }

    public function test_unknown_policy_name_fails_with_configuration_path(): void
    {
        try {
            ResiliencePipelineResolver::resolve('missing', ResilienceConfigurationLoader::load(['policies' => []]), $this->factory());
            self::fail('Unknown policy was resolved.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resilience.policies.missing', $exception->getMessage());
        }
    }

    private function factory(?FakeClock $clock = null): ResiliencePipelineFactory
    {
        return new ResiliencePipelineFactory($clock ?? new FakeClock, new InMemoryStateStore);
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
