<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Diagnostics;

use Throwable;
use Tusk\Cloud\Resilience\Configuration\ResiliencePolicyConfiguration;
use Tusk\Contracts\Cloud\Resilience\StateStoreInterface;

final class ResilienceDiagnosticsRegistry
{
    /** @var array<string, list<string>> */
    private array $policies = [];

    private bool $reportable = true;

    public function __construct(private readonly StateStoreInterface $store) {}

    public function register(ResiliencePolicyConfiguration $policy): void
    {
        $name = $policy->name();
        if (! self::validName($name) || count($this->policies) >= 256 && ! isset($this->policies[$name])) {
            $this->reportable = false;

            return;
        }

        $features = [];
        foreach (['retry' => $policy->retry(), 'circuit_breaker' => $policy->circuitBreaker(), 'bulkhead' => $policy->bulkhead(), 'rate_limit' => $policy->rateLimit()] as $feature => $settings) {
            if ($settings !== null) {
                $features[] = $feature;
            }
        }
        $this->policies[$name] = $features;
    }

    public function contains(string $name): bool
    {
        return isset($this->policies[$name]);
    }

    public function snapshot(): ResilienceDiagnosticsSnapshot
    {
        if (! $this->reportable) {
            return new ResilienceDiagnosticsSnapshot([], [], false);
        }

        $policies = $this->policies;
        ksort($policies, SORT_STRING);
        $policyEntries = [];
        $circuits = [];
        foreach ($policies as $name => $features) {
            $policyEntries[] = ['name' => $name, 'features' => $features];
            if (! in_array('circuit_breaker', $features, true)) {
                continue;
            }

            $state = 'unknown';
            try {
                $stored = $this->store->get('cb:'.$name);
                $state = self::confirmedState($stored) ?? 'unknown';
            } catch (Throwable) {
                // Diagnostics cannot affect the operation or claim an unconfirmed state.
            }
            $circuits[] = ['name' => $name, 'state' => $state];
        }

        return new ResilienceDiagnosticsSnapshot($policyEntries, $circuits);
    }

    private static function validName(string $name): bool
    {
        return strlen($name) <= 128 && preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name) === 1;
    }

    /** @param array<string, mixed>|null $stored */
    private static function confirmedState(?array $stored): ?string
    {
        if ($stored === null
            || ! isset($stored['state'], $stored['failureCount'], $stored['halfOpenProbeCount'], $stored['halfOpenGeneration'])
            || ! array_key_exists('openedAtMilliseconds', $stored)
            || ! is_string($stored['state'])
            || ! in_array($stored['state'], ['CLOSED', 'OPEN', 'HALF_OPEN'], true)
            || ! is_int($stored['failureCount']) || $stored['failureCount'] < 0
            || ! is_int($stored['halfOpenProbeCount']) || $stored['halfOpenProbeCount'] < 0
            || ! is_string($stored['halfOpenGeneration'])
            || preg_match('/\A[0-9a-f]{32}:(0|[1-9][0-9]*)\z/D', $stored['halfOpenGeneration'], $matches) !== 1
            || filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            return null;
        }

        if ($stored['state'] === 'CLOSED') {
            return $stored['openedAtMilliseconds'] === null && $stored['halfOpenProbeCount'] === 0 ? 'closed' : null;
        }

        if (! is_int($stored['openedAtMilliseconds']) || $stored['openedAtMilliseconds'] < 0
            || $stored['state'] === 'OPEN' && $stored['halfOpenProbeCount'] !== 0) {
            return null;
        }

        return $stored['state'] === 'OPEN' ? 'open' : 'half_open';
    }
}
