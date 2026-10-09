<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Tusk\Cloud\Resilience\Diagnostics\EngineResilienceReporter;
use Tusk\Cloud\Resilience\Diagnostics\ResilienceDiagnosticsRegistry;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;
use Tusk\Contracts\Events\EventDispatcherInterface;
use Tusk\Contracts\Observability\TelemetryProviderInterface;
use WeakReference;

final class ResiliencePipelineFactory
{
    /** @var array<string, WeakReference<CircuitBreaker>> */
    private array $circuitBreakers = [];

    private readonly ResilienceInstrumentation $instrumentation;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly StateStoreInterface $stateStore,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?TelemetryProviderInterface $telemetry = null,
        ?ResilienceDiagnosticsRegistry $registry = null,
        ?EngineResilienceReporter $reporter = null,
    ) {
        $this->instrumentation = new ResilienceInstrumentation($eventDispatcher, $telemetry, $clock, $registry, $reporter);
    }

    public function pipeline(string $name): ResiliencePipelineBuilder
    {
        $name = OperationContext::create($name)->operation();

        foreach ($this->circuitBreakers as $breakerName => $reference) {
            if ($reference->get() === null) {
                unset($this->circuitBreakers[$breakerName]);
            }
        }

        $breaker = ($this->circuitBreakers[$name] ?? null)?->get();
        if (! $breaker instanceof CircuitBreaker) {
            $breaker = new CircuitBreaker($this->stateStore, $this->clock, $name, $this->instrumentation);
            $this->circuitBreakers[$name] = WeakReference::create($breaker);
        }

        return ResiliencePipelineBuilder::create(
            $name,
            new RetryExecutor($this->clock, $this->instrumentation),
            $breaker,
            new Bulkhead($this->clock),
            new RateLimiter($this->clock),
            $this->instrumentation,
        );
    }
}
