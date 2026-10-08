<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\BulkheadPolicy;
use Tusk\Cloud\Resilience\CircuitBreakerPolicy;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Cloud\Resilience\Exception\ResilienceFallbackException;
use Tusk\Cloud\Resilience\InMemoryStateStore;
use Tusk\Cloud\Resilience\RateLimitPolicy;
use Tusk\Cloud\Resilience\ResiliencePipelineFactory;
use Tusk\Cloud\Resilience\RetryPolicy;
use Tusk\Cloud\Resilience\Testing\FakeClock;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\FailureDecision;
use Tusk\Contracts\Cloud\Resilience\OperationContext;

final class ResiliencePipelineTest extends TestCase
{
    public function test_pipeline_builder_is_immutable_and_propagates_the_same_context(): void
    {
        $factory = new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore);
        $builder = $factory->pipeline('payments.charge');
        $configured = $builder->withBulkhead(BulkheadPolicy::create());
        $context = OperationContext::create('payments.charge');

        self::assertNotSame($builder, $configured);
        self::assertSame('receipt', $configured->run(static function (OperationContext $received) use ($context): string {
            self::assertSame($context, $received);

            return 'receipt';
        }, $context));
    }

    public function test_circuit_breaker_records_only_the_final_retry_outcome(): void
    {
        $store = new InMemoryStateStore;
        $factory = new ResiliencePipelineFactory(new FakeClock, $store);
        $pipeline = $factory->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1));
        $context = OperationContext::create('payments.charge', retryAllowed: true);
        $attempts = 0;

        $result = $pipeline->run(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('transient');
            }

            return 'paid';
        }, $context);

        self::assertSame('paid', $result);
        self::assertSame(2, $attempts);
        self::assertSame('CLOSED', $store->get('cb:payments.charge')['state']);
        self::assertSame(0, $store->get('cb:payments.charge')['failureCount']);
    }

    public function test_circuit_breaker_respects_failure_classification(): void
    {
        $store = new InMemoryStateStore;
        $classifier = new class implements FailureClassifierInterface
        {
            public function classify(\Throwable $failure, OperationContext $context): FailureDecision
            {
                return FailureDecision::terminal(circuitFailure: false);
            }
        };
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, $store))
            ->pipeline('payments.charge')
            ->withRetry(RetryPolicy::create(maxAttempts: 1, classifier: $classifier))
            ->withCircuitBreaker(CircuitBreakerPolicy::create(failureThreshold: 1));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $pipeline->run(static function (): never {
                    throw new RuntimeException('caller cancellation');
                });
                self::fail('Operation failure was swallowed.');
            } catch (RuntimeException $failure) {
                self::assertSame('caller cancellation', $failure->getMessage());
            }
        }

        self::assertNull($store->get('cb:payments.charge'));
    }

    public function test_factory_reuses_same_named_breaker_and_bounds_shared_half_open_probes(): void
    {
        $clock = new FakeClock;
        $factory = new ResiliencePipelineFactory($clock, new InMemoryStateStore);
        $policy = CircuitBreakerPolicy::create(failureThreshold: 1, openDurationMilliseconds: 10, halfOpenProbeLimit: 1);
        $first = $factory->pipeline('payments.charge')->withCircuitBreaker($policy);
        $second = $factory->pipeline('payments.charge')->withCircuitBreaker($policy);

        try {
            $first->run(static function (): never {
                throw new RuntimeException('provider down');
            });
            self::fail('Initial circuit failure was swallowed.');
        } catch (RuntimeException $failure) {
            self::assertSame('provider down', $failure->getMessage());
        }

        $clock->sleepMilliseconds(10);
        $probe = new \Fiber(static function () use ($first): string {
            return $first->run(static function (): string {
                \Fiber::suspend();

                return 'probe';
            });
        });
        $probe->start();
        $secondOperationCalled = false;

        try {
            $second->run(static function () use (&$secondOperationCalled): string {
                $secondOperationCalled = true;

                return 'over-admitted';
            });
            self::fail('A second named pipeline exceeded the half-open probe limit.');
        } catch (CircuitOpenException) {
            self::assertFalse($secondOperationCalled);
        } finally {
            $probe->resume();
        }

        self::assertSame('probe', $probe->getReturn());
    }

    public function test_rate_limiter_is_acquired_for_every_retry_attempt(): void
    {
        $clock = new FakeClock;
        $pipeline = (new ResiliencePipelineFactory($clock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withRetry($this->retryPolicy(2))
            ->withRateLimit(RateLimitPolicy::create(capacity: 1, refillPerSecond: 1, maxWaitMilliseconds: 1_000));
        $context = OperationContext::create('payments.charge', retryAllowed: true);
        $attempts = 0;

        self::assertSame('paid', $pipeline->run(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('transient');
            }

            return 'paid';
        }, $context));
        self::assertSame(2, $attempts);
        self::assertSame(1_000, $clock->nowMilliseconds());
    }

    public function test_bulkhead_slot_is_released_after_fallback_handles_failure(): void
    {
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withBulkhead(BulkheadPolicy::create(maxConcurrent: 1))
            ->withFallback(static fn (\Throwable $failure, OperationContext $context): string => 'fallback');
        $context = OperationContext::create('payments.charge');

        self::assertSame('fallback', $pipeline->run(static function (): never {
            throw new RuntimeException('provider down');
        }, $context));
        self::assertSame('recovered', $pipeline->run(static fn (): string => 'recovered', $context));
    }

    public function test_fallback_runs_only_after_a_failure(): void
    {
        $fallbackCalls = 0;
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withFallback(static function () use (&$fallbackCalls): string {
                $fallbackCalls++;

                return 'fallback';
            });

        self::assertSame('paid', $pipeline->run(static fn (): string => 'paid'));
        self::assertSame(0, $fallbackCalls);
    }

    public function test_original_throwable_is_preserved_without_fallback(): void
    {
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))->pipeline('payments.charge');
        $failure = new LogicException('operation failed');

        try {
            $pipeline->run(static function () use ($failure): never {
                throw $failure;
            });
            self::fail('Operation failure was swallowed.');
        } catch (LogicException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function test_fallback_failure_keeps_both_failures_available(): void
    {
        $original = new RuntimeException('operation failed');
        $fallbackFailure = new LogicException('fallback failed');
        $pipeline = (new ResiliencePipelineFactory(new FakeClock, new InMemoryStateStore))
            ->pipeline('payments.charge')
            ->withFallback(static function () use ($fallbackFailure): never {
                throw $fallbackFailure;
            });

        try {
            $pipeline->run(static function () use ($original): never {
                throw $original;
            });
            self::fail('Fallback failure was swallowed.');
        } catch (ResilienceFallbackException $caught) {
            self::assertSame($original, $caught->getPrevious());
            self::assertSame($fallbackFailure, $caught->fallbackFailure());
        }
    }

    private function retryPolicy(int $maxAttempts): RetryPolicy
    {
        return RetryPolicy::create(
            maxAttempts: $maxAttempts,
            backoffStrategy: FixedBackoff::create(),
            classifier: new class implements FailureClassifierInterface
            {
                public function classify(\Throwable $failure, OperationContext $context): FailureDecision
                {
                    return FailureDecision::retryable();
                }
            },
        );
    }
}
