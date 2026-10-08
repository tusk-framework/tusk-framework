<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Configuration;

use InvalidArgumentException;
use Throwable;

final class ResilienceConfigurationLoader
{
    private const ROOT_KEYS = ['policies', 'profiles'];

    private const POLICY_SECTIONS = ['retry', 'circuit_breaker', 'bulkhead', 'rate_limit'];

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function load(array $values, ?string $profile = null): ResilienceConfiguration
    {
        self::assertMap($values, 'resilience');
        self::assertKnownKeys($values, self::ROOT_KEYS, 'resilience');

        $policies = $values['policies'] ?? [];
        if (! is_array($policies)) {
            throw new InvalidArgumentException('Configuration at resilience.policies must be a named map.');
        }
        self::assertMap($policies, 'resilience.policies');

        if (array_key_exists('profiles', $values)) {
            if (! is_array($values['profiles'])) {
                throw new InvalidArgumentException('Configuration at resilience.profiles must be a named map.');
            }
            self::assertMap($values['profiles'], 'resilience.profiles');
            foreach (array_keys($values['profiles']) as $profileName) {
                if (! is_string($profileName) || trim($profileName) === '') {
                    throw new InvalidArgumentException('Configuration at resilience.profiles requires non-empty string profile names.');
                }
            }
        }

        if ($profile !== null) {
            $profile = trim($profile);
            if ($profile === '') {
                throw new InvalidArgumentException('Configuration profile name at resilience.profiles.<name> must not be blank.');
            }

            $profiles = $values['profiles'] ?? [];
            if ($profiles !== [] && ! array_key_exists($profile, $profiles)) {
                throw new InvalidArgumentException(sprintf('No configuration found at resilience.profiles.%s.', $profile));
            }

            $profileValues = $profiles[$profile] ?? [];
            if (! is_array($profileValues)) {
                throw new InvalidArgumentException(sprintf('Configuration at resilience.profiles.%s must be a named map.', $profile));
            }
            self::assertMap($profileValues, sprintf('resilience.profiles.%s', $profile));
            self::assertKnownKeys($profileValues, ['policies'], sprintf('resilience.profiles.%s', $profile));

            $profilePolicies = $profileValues['policies'] ?? [];
            if (! is_array($profilePolicies)) {
                throw new InvalidArgumentException(sprintf('Configuration at resilience.profiles.%s.policies must be a named map.', $profile));
            }
            self::assertMap($profilePolicies, sprintf('resilience.profiles.%s.policies', $profile));

            $policies = self::mergeMaps($policies, $profilePolicies);
        }

        self::validatePolicies($policies);

        return ResilienceConfiguration::fromArray($policies);
    }

    /** @param array<array-key, mixed> $policies */
    private static function validatePolicies(array $policies): void
    {
        foreach ($policies as $name => $policy) {
            $policyPath = sprintf('resilience.policies.%s', (string) $name);
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException(sprintf('Configuration at %s requires a non-empty string policy name.', $policyPath));
            }
            if (! is_array($policy)) {
                throw new InvalidArgumentException(sprintf('Configuration at %s must be a named map.', $policyPath));
            }
            self::assertMap($policy, $policyPath);
            self::assertKnownKeys($policy, self::POLICY_SECTIONS, $policyPath);

            foreach ($policy as $section => $settings) {
                $sectionPath = sprintf('%s.%s', $policyPath, $section);
                if (! is_array($settings)) {
                    throw new InvalidArgumentException(sprintf('Configuration at %s must be a named map.', $sectionPath));
                }
                self::assertMap($settings, $sectionPath);

                match ($section) {
                    'retry' => self::validateRetry($settings, $sectionPath),
                    'circuit_breaker' => self::validateSettings($settings, [
                        'failure_threshold' => 'positive_int',
                        'open_duration_ms' => 'positive_int',
                        'half_open_probe_limit' => 'positive_int',
                    ], $sectionPath),
                    'bulkhead' => self::validateSettings($settings, [
                        'max_concurrent' => 'positive_int',
                        'max_queued' => 'non_negative_int',
                    ], $sectionPath),
                    'rate_limit' => self::validateSettings($settings, [
                        'capacity' => 'positive_int',
                        'refill_per_second' => 'positive_number',
                        'max_wait_ms' => 'non_negative_int',
                    ], $sectionPath),
                    default => null,
                };
            }
        }
    }

    /** @param array<array-key, mixed> $settings */
    private static function validateRetry(array $settings, string $path): void
    {
        self::assertKnownKeys($settings, ['max_attempts', 'backoff', 'allow_unsafe_retries', 'retry_on', 'do_not_retry_on'], $path);
        if (array_key_exists('max_attempts', $settings)) {
            self::assertInteger($settings['max_attempts'], sprintf('%s.max_attempts', $path), 1);
        }
        if (array_key_exists('allow_unsafe_retries', $settings) && ! is_bool($settings['allow_unsafe_retries'])) {
            throw new InvalidArgumentException(sprintf('Configuration at %s.allow_unsafe_retries must be a boolean.', $path));
        }
        if (array_key_exists('backoff', $settings)) {
            if (! is_array($settings['backoff'])) {
                throw new InvalidArgumentException(sprintf('Configuration at %s.backoff must be a named map.', $path));
            }
            self::assertMap($settings['backoff'], sprintf('%s.backoff', $path));
            self::validateSettings($settings['backoff'], [
                'type' => 'backoff_type',
                'base_delay_ms' => 'non_negative_int',
                'max_delay_ms' => 'non_negative_int',
            ], sprintf('%s.backoff', $path));
            if (! array_key_exists('type', $settings['backoff'])) {
                throw new InvalidArgumentException(sprintf('Configuration at %s.backoff.type is required.', $path));
            }
        }
        foreach (['retry_on', 'do_not_retry_on'] as $field) {
            if (! array_key_exists($field, $settings)) {
                continue;
            }
            $fieldPath = sprintf('%s.%s', $path, $field);
            if (! is_array($settings[$field]) || ! array_is_list($settings[$field])) {
                throw new InvalidArgumentException(sprintf('Configuration at %s must be a list of throwable class names.', $fieldPath));
            }
            foreach ($settings[$field] as $class) {
                if (! is_string($class) || ! class_exists($class) || ! is_a($class, Throwable::class, true)) {
                    throw new InvalidArgumentException(sprintf('Configuration at %s must contain existing throwable class names.', $fieldPath));
                }
            }
        }
    }

    /** @param array<array-key, mixed> $settings @param array<string, string> $rules */
    private static function validateSettings(array $settings, array $rules, string $path): void
    {
        self::assertKnownKeys($settings, array_keys($rules), $path);
        foreach ($settings as $key => $value) {
            $fieldPath = sprintf('%s.%s', $path, (string) $key);
            $rule = $rules[$key];
            if ($rule === 'positive_int') {
                self::assertInteger($value, $fieldPath, 1);
            } elseif ($rule === 'non_negative_int') {
                self::assertInteger($value, $fieldPath, 0);
            } elseif ($rule === 'positive_number') {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
                    throw new InvalidArgumentException(sprintf('Configuration at %s must be a finite positive number.', $fieldPath));
                }
            } elseif ($rule === 'backoff_type' && (! is_string($value) || ! in_array($value, ['fixed', 'exponential', 'decorrelated_jitter'], true))) {
                throw new InvalidArgumentException(sprintf('Configuration at %s must be fixed, exponential, or decorrelated_jitter.', $fieldPath));
            }
        }
    }

    private static function assertInteger(mixed $value, string $path, int $minimum): void
    {
        if (! is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException(sprintf('Configuration at %s must be an integer greater than or equal to %d.', $path, $minimum));
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function assertMap(array $values, string $path): void
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Configuration at %s must be a named map.', $path));
        }
    }

    /** @param array<array-key, mixed> $values @param list<string> $allowed */
    private static function assertKnownKeys(array $values, array $allowed, string $path): void
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unknown configuration key at %s.%s.', $path, (string) $key));
            }
        }
    }

    /** @param array<array-key, mixed> $base @param array<array-key, mixed> $override @return array<array-key, mixed> */
    private static function mergeMaps(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value) && ! array_is_list($base[$key])) {
                $base[$key] = self::mergeMaps($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
