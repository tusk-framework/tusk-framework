<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfigurationLoader;

final class ResilienceConfigurationLoaderTest extends TestCase
{
    public function test_it_loads_base_policies_without_requiring_profile_configuration(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['payments' => ['retry' => ['max_attempts' => 3]]],
        ]);

        self::assertSame(3, $configuration->policy('payments')?->retry()['max_attempts']);
    }

    public function test_it_recursively_merges_maps_and_replaces_lists_and_scalars_for_active_profile(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => [
                'payments' => [
                    'retry' => [
                        'max_attempts' => 3,
                        'allow_unsafe_retries' => false,
                        'retry_on' => [RuntimeException::class, \LogicException::class],
                        'backoff' => ['type' => 'exponential', 'base_delay_ms' => 100],
                    ],
                    'circuit_breaker' => ['failure_threshold' => 5],
                ],
            ],
            'profiles' => [
                'production' => [
                    'policies' => [
                        'payments' => [
                            'retry' => [
                                'max_attempts' => 4,
                                'allow_unsafe_retries' => true,
                                'retry_on' => [\DomainException::class],
                                'backoff' => ['max_delay_ms' => 900],
                            ],
                            'circuit_breaker' => ['failure_threshold' => 7],
                        ],
                    ],
                ],
            ],
        ], 'production');

        self::assertSame(
            [
                'max_attempts' => 4,
                'allow_unsafe_retries' => true,
                'retry_on' => [\DomainException::class],
                'backoff' => ['type' => 'exponential', 'base_delay_ms' => 100, 'max_delay_ms' => 900],
            ],
            $configuration->policy('payments')?->retry(),
        );
        self::assertSame(['failure_threshold' => 7], $configuration->policy('payments')?->circuitBreaker());
    }

    public function test_profile_only_policies_are_included(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['base' => ['bulkhead' => ['max_concurrent' => 2]]],
            'profiles' => ['testing' => ['policies' => ['test-only' => ['rate_limit' => ['capacity' => 4]]]]],
        ], 'testing');

        self::assertSame(['base', 'test-only'], array_keys($configuration->all()));
        self::assertSame(['capacity' => 4], $configuration->policy('test-only')?->rateLimit());
    }

    public function test_inactive_profile_is_not_validated_or_merged(): void
    {
        $configuration = ResilienceConfigurationLoader::load([
            'policies' => ['base' => []],
        ], 'production');

        self::assertSame(['base'], array_keys($configuration->all()));
    }

    public function test_invalid_profile_and_unknown_configuration_keys_have_actionable_paths(): void
    {
        foreach (
            [
                [['policies' => [], 'unknown' => 'private'], null, 'resilience.unknown'],
                [['policies' => [], 'profiles' => ['prod' => []]], 'missing', 'resilience.profiles.missing'],
                [['policies' => ['payments' => ['timeout' => 'private']]], null, 'resilience.policies.payments.timeout'],
            ] as [$values, $profile, $path]
        ) {
            try {
                ResilienceConfigurationLoader::load($values, $profile);
                self::fail('Invalid configuration was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($path, $exception->getMessage());
                self::assertStringNotContainsString('private', $exception->getMessage());
            }
        }
    }

    public function test_it_validates_policy_bounds_and_exception_classes(): void
    {
        foreach (
            [
                ['retry' => ['max_attempts' => 0]],
                ['retry' => ['backoff' => ['type' => 'unknown']]],
                ['retry' => ['retry_on' => ['Missing\ThrowableClass']]],
                ['retry' => ['retry_on' => [RuntimeException::class], 'do_not_retry_on' => ['stdClass']]],
                ['circuit_breaker' => ['failure_threshold' => 0]],
                ['bulkhead' => ['max_concurrent' => 0]],
                ['rate_limit' => ['refill_per_second' => 0]],
            ] as $policy
        ) {
            try {
                ResilienceConfigurationLoader::load(['policies' => ['payments' => $policy]]);
                self::fail('Invalid policy settings were accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('resilience.policies.payments', $exception->getMessage());
                self::assertStringNotContainsString('Missing\\ThrowableClass', $exception->getMessage());
            }
        }
    }
}
