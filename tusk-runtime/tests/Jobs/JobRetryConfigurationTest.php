<?php

namespace Tusk\Runtime\Tests\Jobs;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Runtime\Jobs\JobRetryConfiguration;

final class JobRetryConfigurationTest extends TestCase
{
    public function test_defaults_and_explicit_values(): void
    {
        $defaults = JobRetryConfiguration::fromArray([]);
        self::assertSame(3, $defaults->maxAttempts());
        self::assertSame(1, $defaults->delaySeconds());

        $custom = JobRetryConfiguration::fromArray(['max_attempts' => 5, 'delay_seconds' => 0]);
        self::assertSame(5, $custom->maxAttempts());
        self::assertSame(0, $custom->delaySeconds());
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([['max_attempts' => 0], ['max_attempts' => -1], ['delay_seconds' => -1], ['max_attempts' => '3'], ['delay_seconds' => '1']] as $values) {
            try {
                JobRetryConfiguration::fromArray($values);
                self::fail('Expected invalid retry configuration.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
    }
}
