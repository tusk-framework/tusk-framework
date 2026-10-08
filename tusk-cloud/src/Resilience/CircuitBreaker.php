<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience;

use InvalidArgumentException;
use Throwable;
use Tusk\Cloud\Resilience\Exception\CircuitOpenException;
use Tusk\Contracts\Cloud\Resilience\ClockInterface;
use Tusk\Contracts\Cloud\Resilience\OperationContext;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

final class CircuitBreaker implements CircuitBreakerInterface
{
    private readonly string $key;

    public function __construct(
        private readonly StateStoreInterface $store,
        private readonly ClockInterface $clock,
        string $name,
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Circuit name cannot be blank.');
        }

        $this->key = "cb:{$name}";
    }

    public function execute(callable $operation, OperationContext $context, CircuitBreakerPolicy $policy): mixed
    {
        $snapshot = $this->snapshot();
        $probe = false;
        $admissionGeneration = $snapshot['halfOpenGeneration'];

        if ($snapshot['state'] === State::OPEN->value) {
            $openedAt = $snapshot['openedAtMilliseconds'];
            $now = $this->clock->nowMilliseconds();
            if ($now < $openedAt || $now - $openedAt < $policy->openDurationMilliseconds()) {
                throw new CircuitOpenException;
            }
            if ($snapshot['halfOpenGeneration'] === PHP_INT_MAX) {
                throw new CircuitOpenException('Circuit probe generation is exhausted.');
            }

            $snapshot['state'] = State::HALF_OPEN->value;
            $snapshot['halfOpenProbeCount'] = 0;
            $snapshot['halfOpenGeneration']++;
        }

        if ($snapshot['state'] === State::HALF_OPEN->value) {
            if ($snapshot['halfOpenProbeCount'] >= $policy->halfOpenProbeLimit()) {
                throw new CircuitOpenException;
            }

            // Reserve before calling user code. The in-memory store provides
            // process-local admission; distributed atomicity needs a store adapter.
            $snapshot['halfOpenProbeCount']++;
            $this->saveSnapshot($snapshot);
            $probe = true;
            $admissionGeneration = $snapshot['halfOpenGeneration'];
        }

        try {
            $result = $operation($context);
        } catch (Throwable $failure) {
            try {
                $current = $this->snapshot();
                if ($probe && $current['halfOpenGeneration'] === $admissionGeneration
                    && in_array($current['state'], [State::HALF_OPEN->value, State::CLOSED->value], true)) {
                    // A failed in-flight probe wins over an earlier successful
                    // probe from the same half-open admission round.
                    $this->saveSnapshot($this->openSnapshot($policy, $this->clock->nowMilliseconds(), $admissionGeneration));
                } elseif (! $probe && $current['state'] === State::CLOSED->value) {
                    $current['failureCount']++;
                    if ($current['failureCount'] >= $policy->failureThreshold()) {
                        $current = $this->openSnapshot($policy, $this->clock->nowMilliseconds(), $current['halfOpenGeneration']);
                    }
                    $this->saveSnapshot($current);
                }
            } catch (Throwable) {
                // Accounting infrastructure must never replace the operation failure.
            }

            throw $failure;
        }

        $current = $this->snapshot();
        if ($probe && $current['state'] === State::HALF_OPEN->value && $current['halfOpenGeneration'] === $admissionGeneration) {
            $this->saveSnapshot($this->closedSnapshot($admissionGeneration));
        } elseif (! $probe && $current['state'] === State::CLOSED->value) {
            // This policy counts consecutive failed logical operations.
            // Rolling-window accounting is deferred to a future policy/store.
            $current['failureCount'] = 0;
            $this->saveSnapshot($current);
        }

        return $result;
    }

    public function state(): State
    {
        return State::from($this->snapshot()['state']);
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: int}
     */
    public function snapshot(): array
    {
        $data = $this->store->get($this->key);
        if ($data === null || ! $this->validSnapshot($data)) {
            return $this->closedSnapshot();
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
            || ! is_int($data['halfOpenGeneration']) || $data['halfOpenGeneration'] < 0
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
     * @return array{state: string, failureCount: int, openedAtMilliseconds: null, halfOpenProbeCount: int, halfOpenGeneration: int}
     */
    private function closedSnapshot(int $generation = 0): array
    {
        return ['state' => State::CLOSED->value, 'failureCount' => 0, 'openedAtMilliseconds' => null, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $generation];
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int, halfOpenProbeCount: int, halfOpenGeneration: int}
     */
    private function openSnapshot(CircuitBreakerPolicy $policy, int $now, int $generation): array
    {
        return ['state' => State::OPEN->value, 'failureCount' => $policy->failureThreshold(), 'openedAtMilliseconds' => $now, 'halfOpenProbeCount' => 0, 'halfOpenGeneration' => $generation];
    }

    /** @param array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int, halfOpenGeneration: int} $snapshot */
    private function saveSnapshot(array $snapshot): void
    {
        $this->store->set($this->key, $snapshot);
    }
}
