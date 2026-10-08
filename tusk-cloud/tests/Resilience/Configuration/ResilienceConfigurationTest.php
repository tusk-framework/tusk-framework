<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\Configuration\ResilienceConfiguration;
use Tusk\Cloud\Resilience\Configuration\ResiliencePolicyConfiguration;

final class ResilienceConfigurationTest extends TestCase
{
    public function test_it_creates_a_named_policy_with_optional_policy_sections(): void
    {
        $configuration = ResiliencePolicyConfiguration::fromArray(' payments ', [
            'retry' => ['maxAttempts' => 3],
            'circuit_breaker' => ['failureThreshold' => 5],
        ]);

        self::assertSame('payments', $configuration->name());
        self::assertSame(['maxAttempts' => 3], $configuration->retry());
        self::assertSame(['failureThreshold' => 5], $configuration->circuitBreaker());
        self::assertNull($configuration->bulkhead());
        self::assertNull($configuration->rateLimit());
    }

    public function test_it_creates_empty_and_named_policy_collections(): void
    {
        self::assertSame([], ResilienceConfiguration::fromArray([])->all());

        $configuration = ResilienceConfiguration::fromArray([
            'payments' => ['retry' => ['maxAttempts' => 2]],
            'inventory' => ['bulkhead' => ['maxConcurrent' => 4]],
        ]);

        self::assertSame(['payments', 'inventory'], array_keys($configuration->all()));
        self::assertSame('payments', $configuration->policy('payments')?->name());
        self::assertNull($configuration->policy('missing'));
    }

    public function test_it_rejects_a_blank_policy_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResiliencePolicyConfiguration::fromArray(" \t ", []);
    }

    public function test_it_rejects_an_unknown_policy_section_without_echoing_values(): void
    {
        try {
            ResiliencePolicyConfiguration::fromArray('payments', ['secret_policy' => 'sensitive-value']);
            self::fail('Unknown policy section was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resilience.policies.payments.secret_policy', $exception->getMessage());
            self::assertStringNotContainsString('sensitive-value', $exception->getMessage());
        }
    }

    public function test_it_rejects_a_non_map_policy_section(): void
    {
        try {
            ResiliencePolicyConfiguration::fromArray('payments', ['retry' => 'sensitive-value']);
            self::fail('Scalar policy section was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resilience.policies.payments.retry', $exception->getMessage());
            self::assertStringNotContainsString('sensitive-value', $exception->getMessage());
        }
    }

    public function test_it_rejects_non_empty_lists_where_named_maps_are_required(): void
    {
        foreach (
            [
                static fn () => ResilienceConfiguration::fromArray([['retry' => []]]),
                static fn () => ResiliencePolicyConfiguration::fromArray('payments', ['retry' => [['maxAttempts' => 2]]]),
            ] as $invalidConfiguration
        ) {
            $exceptionThrown = false;
            try {
                $invalidConfiguration();
            } catch (InvalidArgumentException) {
                $exceptionThrown = true;
            }

            self::assertTrue($exceptionThrown, 'List configuration was accepted as a named map.');
        }
    }

    public function test_it_rejects_names_that_collide_after_trimming(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResilienceConfiguration::fromArray([
            'payments' => [],
            ' payments ' => [],
        ]);
    }
}
