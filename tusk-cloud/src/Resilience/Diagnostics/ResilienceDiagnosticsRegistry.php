<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Diagnostics;

use Tusk\Cloud\Resilience\Configuration\ResiliencePolicyConfiguration;

final class ResilienceDiagnosticsRegistry
{
    /** @var array<string, list<string>> */
    private array $policies = [];

    /** @var array<string, string> */
    private array $circuitStates = [];

    private bool $reportable = true;

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
        if (in_array('circuit_breaker', $features, true)) {
            $this->circuitStates[$name] ??= 'unknown';
        } else {
            unset($this->circuitStates[$name]);
        }
    }

    public function recordCircuitState(string $name, string $state): bool
    {
        if (! isset($this->circuitStates[$name]) || ! in_array($state, ['closed', 'open', 'half_open', 'unknown'], true)) {
            return false;
        }

        $this->circuitStates[$name] = $state;

        return true;
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
            if (isset($this->circuitStates[$name])) {
                $circuits[] = ['name' => $name, 'state' => $this->circuitStates[$name]];
            }
        }

        return new ResilienceDiagnosticsSnapshot($policyEntries, $circuits);
    }

    private static function validName(string $name): bool
    {
        return strlen($name) <= 128 && preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name) === 1;
    }
}
