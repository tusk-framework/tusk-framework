<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Throwable;
use Tusk\Cloud\Resilience\Event\CircuitStateChanged;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\FailureClassifierInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

final class CircuitBreaker implements CircuitBreakerInterface
{
    private readonly string $key;

    private readonly string $generationNamespace;

    private int $generationFloor = 0;

    private int $activeProbeCount = 0;

    public function __construct(
        private readonly StateStoreInterface $store,
        private readonly ClockInterface $clock,
        string $name,
        private readonly ?ResilienceInstrumentation $instrumentation = null,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Circuit name cannot be blank.');
        }

        $this->key = "cb:{$name}";
        $this->generationNamespace = bin2hex(random_bytes(16));
    }

    public function execute(
        callable $operation,
        OperationContext $context,
        CircuitBreakerPolicy $policy,
        ?FailureClassifierInterface $classifier = null,
    ): mixed {
        $snapshot = $this->snapshot();
        $admissionState = State::from($snapshot['state']);
        $probe = false;
        $admissionGeneration = $snapshot['halfOpenGeneration'];

        if ($snapshot['state'] === State::OPEN->value) {
            $openedAt = $snapshot['openedAtMilliseconds'];
            $now = $this->clock->nowMilliseconds();
            if ($now < $openedAt || $now - $openedAt < $policy->openDurationMilliseconds()) {
                throw new CircuitOpenException;
            }
            if ($this->generationFloor === PHP_INT_MAX) {
                throw new CircuitOpenException('Circuit probe generation is exhausted.');
            }

            $snapshot['state'] = State::HALF_OPEN->value;
            $snapshot['halfOpenProbeCount'] = 0;
            $snapshot['halfOpenGeneration'] = $this->nextGeneration();
        }

        if ($snapshot['state'] === State::HALF_OPEN->value) {
            if ($snapshot['halfOpenProbeCount'] >= $policy->halfOpenProbeLimit()) {
                if ($this->activeProbeCount > 0) {
                    throw new CircuitOpenException;
                }

                // A prior process-local probe may have completed while its
                // state-store writes failed. Rotate the generation only when
                // this breaker has no live probe, making abandoned tickets
                // recoverable without admitting beyond the configured limit.
                $snapshot['halfOpenProbeCount'] = 0;
                $snapshot['halfOpenGeneration'] = $this->nextGeneration();
            }

            // Reserve before calling user code. The in-memory store provides
            // process-local admission; distributed atomicity needs a store adapter.
            $snapshot['halfOpenProbeCount']++;
            $this->saveSnapshot($snapshot);
            $probe = true;
            $this->activeProbeCount++;
            $admissionGeneration = $snapshot['halfOpenGeneration'];
            // A synchronous listener must see the reserved probe as live.
            $this->observeTransition($admissionState, State::HALF_OPEN, $context);
        }

        try {
            $result = $operation($context);
        } catch (Throwable $failure) {
            $mayReopenProbe = false;
            try {
                $current = $this->snapshot();
                $mayReopenProbe = $probe
                    && $current['halfOpenGeneration'] === $admissionGeneration
                    && in_array($current['state'], [State::HALF_OPEN->value, State::CLOSED->value], true);
                $decision = ($classifier ?? new DefaultFailureClassifier)->classify($failure, $context);
                // Classification is user code and may re-enter this breaker.
                // Account only against the state and probe round it leaves behind.
                $current = $this->snapshot();
                if (! $decision->countsAsCircuitFailure() && $probe
                    && $current['state'] === State::HALF_OPEN->value
                    && $current['halfOpenGeneration'] === $admissionGeneration) {
                    $current['halfOpenProbeCount'] = max(0, $current['halfOpenProbeCount'] - 1);
                    $this->saveSnapshot($current);
                } elseif ($decision->countsAsCircuitFailure() && $probe && $current['halfOpenGeneration'] === $admissionGeneration
                    && in_array($current['state'], [State::HALF_OPEN->value, State::CLOSED->value], true)) {
                    // A failed in-flight probe wins over an earlier successful
                    // probe from the same half-open admission round.
                    $this->saveTransition($this->openSnapshot($policy, $this->clock->nowMilliseconds(), $admissionGeneration), State::from($current['state']), $context);
                } elseif ($decision->countsAsCircuitFailure() && ! $probe && $current['state'] === State::CLOSED->value) {
                    $current['failureCount']++;
                    if ($current['failureCount'] >= $policy->failureThreshold()) {
                        $current = $this->openSnapshot($policy, $this->clock->nowMilliseconds(), $current['halfOpenGeneration']);
                    }
                    $this->saveSnapshot($current);
                    $this->observeTransition(State::CLOSED, State::from($current['state']), $context);
                }
            } catch (Throwable) {
                if ($mayReopenProbe) {
                    try {
                        $current = $this->snapshot();
                        if ($current['halfOpenGeneration'] === $admissionGeneration
                            && in_array($current['state'], [State::HALF_OPEN->value, State::CLOSED->value], true)) {
                            $this->saveTransition($this->openSnapshot($policy, $this->clock->nowMilliseconds(), $admissionGeneration), State::from($current['state']), $context);
                        }
                    } catch (Throwable) {
                        // Accounting infrastructure must never replace the operation failure.
                    }
                }
            }

            if ($probe) {
                $this->activeProbeCount--;
            }

            throw $failure;
        }

        if ($probe) {
            $this->activeProbeCount--;
        }

        $mayReopenProbe = false;
        try {
            $current = $this->snapshot();
            $mayReopenProbe = $probe
                && $current['state'] === State::HALF_OPEN->value
                && $current['halfOpenGeneration'] === $admissionGeneration;
            if ($probe && $current['state'] === State::HALF_OPEN->value && $current['halfOpenGeneration'] === $admissionGeneration) {
                $this->saveTransition($this->closedSnapshot($admissionGeneration), State::HALF_OPEN, $context);
            } elseif (! $probe && $current['state'] === State::CLOSED->value) {
                // This policy counts consecutive failed logical operations.
                // Rolling-window accounting is deferred to a future policy/store.
                $current['failureCount'] = 0;
                $this->saveSnapshot($current);
            }
        } catch (Throwable) {
            if ($mayReopenProbe) {
                // If a successful probe cannot close the circuit, best-effort
                // reopen it so a transient store failure cannot strand a full
                // half-open probe budget indefinitely.
                try {
                    $current = $this->snapshot();
                    if ($current['state'] === State::HALF_OPEN->value && $current['halfOpenGeneration'] === $admissionGeneration) {
                        $this->saveTransition($this->openSnapshot($policy, $this->clock->nowMilliseconds(), $admissionGeneration), State::HALF_OPEN, $context);
                    }
                } catch (Throwable) {
                    // Preserve the already successful operation result.
                }
            }
        }

        return $result;
    }

    public function state(): State
    {
        return State::from($this->snapshot()['state']);
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: string}
     */
    public function snapshot(): array
    {
        $data = $this->store->get($this->key);
        if ($data === null || ! $this->validSnapshot($data)) {
            return $this->closedSnapshot();
        }

        [$namespace, $counter] = explode(':', $data['halfOpenGeneration'], 2);
        if ($namespace === $this->generationNamespace) {
            $this->generationFloor = max($this->generationFloor, (int) $counter);
        }

        return [
            'state' => $data['state'],
            'failureCount' => $data['failureCount'],
            'openedAtMilliseconds' => $data['openedAtMilliseconds'],
            'halfOpenProbeCount' => $data['halfOpenProbeCount'],
            'halfOpenGeneration' => $data['halfOpenGeneration'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function validSnapshot(array $data): bool
    {
        if (! isset($data['state'], $data['failureCount'], $data['halfOpenProbeCount'], $data['halfOpenGeneration'])
            || ! array_key_exists('openedAtMilliseconds', $data)
            || ! is_string($data['state'])
            || ! in_array($data['state'], [State::CLOSED->value, State::OPEN->value, State::HALF_OPEN->value], true)
            || ! is_int($data['failureCount']) || $data['failureCount'] < 0
            || ! is_int($data['halfOpenProbeCount']) || $data['halfOpenProbeCount'] < 0
            || ! is_string($data['halfOpenGeneration'])
            || ! preg_match('/\A[0-9a-f]{32}:(0|[1-9][0-9]*)\z/D', $data['halfOpenGeneration'], $matches)
            || filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            return false;
        }

        if ($data['state'] === State::CLOSED->value) {
            return $data['openedAtMilliseconds'] === null && $data['halfOpenProbeCount'] === 0;
        }

        return is_int($data['openedAtMilliseconds']) && $data['openedAtMilliseconds'] >= 0
            && ($data['state'] !== State::OPEN->value || $data['halfOpenProbeCount'] === 0);
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: null, halfOpenProbeCount: int, halfOpenGeneration: string}
     */
    private function closedSnapshot(?string $generation = null): array
    {
        return ['state' => State::CLOSED->value, 'failureCount' => 0, 'openedAtMilliseconds' => null, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $generation ?? "{$this->generationNamespace}:0"];
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int, halfOpenProbeCount: int, halfOpenGeneration: string}
     */
    private function openSnapshot(CircuitBreakerPolicy $policy, int $now, string $generation): array
    {
        return ['state' => State::OPEN->value, 'failureCount' => $policy->failureThreshold(), 'openedAtMilliseconds' => $now, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $generation];
    }

    private function nextGeneration(): string
    {
        return "{$this->generationNamespace}:".(++$this->generationFloor);
    }

    /** @param array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: string} $snapshot */
    private function saveSnapshot(array $snapshot): void
    {
        $this->store->set($this->key, $snapshot);
    }

    /** @param array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: string} $snapshot */
    private function saveTransition(array $snapshot, State $previous, OperationContext $context): void
    {
        $this->saveSnapshot($snapshot);
        $this->observeTransition($previous, State::from($snapshot['state']), $context);
    }

    private function observeTransition(State $previous, State $current, OperationContext $context): void
    {
        if ($previous !== $current) {
            $this->instrumentation?->circuitStateChanged(new CircuitStateChanged($context->operation(), $previous, $current));
        }
    }
}
