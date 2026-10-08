<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;
use WeakReference;

final class ResiliencePipelineFactory
{
    /** @var array<string, WeakReference<CircuitBreaker>> */
    private array $circuitBreakers = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly StateStoreInterface $stateStore,
    ) {}

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
            $breaker = new CircuitBreaker($this->stateStore, $this->clock, $name);
            $this->circuitBreakers[$name] = WeakReference::create($breaker);
        }

        return ResiliencePipelineBuilder::create(
            $name,
            new RetryExecutor($this->clock),
            $breaker,
            new Bulkhead($this->clock),
            new RateLimiter($this->clock),
        );
    }
}
