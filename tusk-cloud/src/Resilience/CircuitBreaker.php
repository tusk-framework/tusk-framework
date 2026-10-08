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

        if ($snapshot['state'] === State::OPEN->value) {
            $openedAt = $snapshot['openedAtMilliseconds'];
            $now = $this->clock->nowMilliseconds();
            if ($now < $openedAt || $now - $openedAt < $policy->openDurationMilliseconds()) {
                throw new CircuitOpenException;
            }

            $snapshot['state'] = State::HALF_OPEN->value;
            $snapshot['halfOpenProbeCount'] = 0;
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
        }

        try {
            $result = $operation($context);
        } catch (Throwable $failure) {
            $current = $this->snapshot();
            if ($probe && in_array($current['state'], [State::HALF_OPEN->value, State::CLOSED->value], true)) {
                // A failed in-flight probe wins over an earlier successful
                // probe from the same half-open admission round.
                $this->saveSnapshot($this->openSnapshot($policy, $this->clock->nowMilliseconds()));
            } elseif (! $probe && $current['state'] === State::CLOSED->value) {
                $current['failureCount']++;
                if ($current['failureCount'] >= $policy->failureThreshold()) {
                    $current = $this->openSnapshot($policy, $this->clock->nowMilliseconds());
                }
                $this->saveSnapshot($current);
            }

            throw $failure;
        }

        $current = $this->snapshot();
        if ($probe && $current['state'] === State::HALF_OPEN->value) {
            $this->saveSnapshot($this->closedSnapshot());
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
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int}
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
        ];
    }

    /** @param array<string, mixed> $data */
    private function validSnapshot(array $data): bool
    {
        if (! isset($data['state'], $data['failureCount'], $data['halfOpenProbeCount'])
            || ! array_key_exists('openedAtMilliseconds', $data)
            || ! is_string($data['state'])
            || ! in_array($data['state'], [State::CLOSED->value, State::OPEN->value, State::HALF_OPEN->value], true)
            || ! is_int($data['failureCount']) || $data['failureCount'] < 0
            || ! is_int($data['halfOpenProbeCount']) || $data['halfOpenProbeCount'] < 0
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
     * @return array{state: string, failureCount: int, openedAtMilliseconds: null, halfOpenProbeCount: int}
     */
    private function closedSnapshot(): array
    {
        return ['state' => State::CLOSED->value, 'failureCount' => 0, 'openedAtMilliseconds' => null, 'halfOpenProbeCount' => 0];
    }

    /**
     * @return array{state: string, failureCount: int, openedAtMilliseconds: int, halfOpenProbeCount: int}
     */
    private function openSnapshot(CircuitBreakerPolicy $policy, int $now): array
    {
        return ['state' => State::OPEN->value, 'failureCount' => $policy->failureThreshold(), 'openedAtMilliseconds' => $now, 'halfOpenProbeCount' => 0];
    }

    /** @param array{state: string, failureCount: int, openedAtMilliseconds: int|null, halfOpenProbeCount: int} $snapshot */
    private function saveSnapshot(array $snapshot): void
    {
        $this->store->set($this->key, $snapshot);
    }
}
