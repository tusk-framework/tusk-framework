<?php

declare(strict_types=1);

namespace Tusk\Cloud\Tests\Resilience;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tusk\Cloud\Resilience\Backoff\DecorrelatedJitterBackoff;
use Tusk\Cloud\Resilience\Backoff\ExponentialBackoff;
use Tusk\Cloud\Resilience\Backoff\FixedBackoff;
use Tusk\Cloud\Resilience\Random\SecureRandomSource;
use Tusk\Cloud\Resilience\Testing\FakeRandomSource;
use Tusk\Contracts\Cloud\Resilience\RandomSourceInterface;

final class BackoffStrategyTest extends TestCase
{
    public function test_fixed_delay_is_capped_and_never_sleeps(): void
    {
        $strategy = FixedBackoff::create(delayMilliseconds: 150, maxDelayMilliseconds: 100);
        self::assertSame(100, $strategy->delayMilliseconds(1, 0));
        self::assertSame(100, $strategy->delayMilliseconds(10, 50));
        self::assertSame(0, FixedBackoff::create()->delayMilliseconds(1, 0));
    }

    public function test_exponential_delay_uses_one_based_retry_number_and_caps_without_overflow(): void
    {
        $strategy = ExponentialBackoff::create(baseDelayMilliseconds: 100, maxDelayMilliseconds: 500);
        self::assertSame(100, $strategy->delayMilliseconds(1, 0));
        self::assertSame(200, $strategy->delayMilliseconds(2, 0));
        self::assertSame(400, $strategy->delayMilliseconds(3, 0));
        self::assertSame(500, $strategy->delayMilliseconds(4, 0));
        self::assertSame(500, $strategy->delayMilliseconds(PHP_INT_MAX, 0));
        self::assertSame(0, ExponentialBackoff::create(baseDelayMilliseconds: 0)->delayMilliseconds(100, 0));
    }

    public function test_exponential_delay_below_small_odd_cap_is_not_saturated_early(): void
    {
        $strategy = ExponentialBackoff::create(baseDelayMilliseconds: 250, maxDelayMilliseconds: 501);

        self::assertSame(500, $strategy->delayMilliseconds(2, 0));
        self::assertSame(501, $strategy->delayMilliseconds(3, 0));
    }

    public function test_exponential_delay_below_php_int_max_odd_cap_does_not_overflow(): void
    {
        $strategy = ExponentialBackoff::create(
            baseDelayMilliseconds: intdiv(PHP_INT_MAX, 2),
            maxDelayMilliseconds: PHP_INT_MAX,
        );

        self::assertSame(PHP_INT_MAX - 1, $strategy->delayMilliseconds(2, 0));
        self::assertSame(PHP_INT_MAX, $strategy->delayMilliseconds(3, 0));
    }

    public function test_decorrelated_jitter_uses_injected_values_previous_delay_and_cap(): void
    {
        $random = new FakeRandomSource([175, 500]);
        $strategy = DecorrelatedJitterBackoff::create($random, baseDelayMilliseconds: 100, maxDelayMilliseconds: 500);
        self::assertSame(175, $strategy->delayMilliseconds(1, 0));
        self::assertSame(500, $strategy->delayMilliseconds(2, 175));
        self::assertSame([[100, 300], [100, 500]], $random->requestedRanges());
    }

    public function test_backoff_rejects_negative_inputs_and_invalid_configuration(): void
    {
        $rejections = 0;
        foreach ([
            static fn () => FixedBackoff::create(delayMilliseconds: -1),
            static fn () => FixedBackoff::create(maxDelayMilliseconds: -1),
            static fn () => ExponentialBackoff::create(baseDelayMilliseconds: -1),
            static fn () => DecorrelatedJitterBackoff::create(new FakeRandomSource, maxDelayMilliseconds: -1),
            static fn () => FixedBackoff::create()->delayMilliseconds(0, 0),
            static fn () => ExponentialBackoff::create()->delayMilliseconds(1, -1),
            static fn () => DecorrelatedJitterBackoff::create(new FakeRandomSource)->delayMilliseconds(0, 0),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid backoff input was accepted.');
            } catch (InvalidArgumentException) {
                $rejections++;
            }
        }

        self::assertSame(7, $rejections);
    }

    public function test_secure_random_source_obeys_inclusive_bounds(): void
    {
        $source = new SecureRandomSource;
        self::assertInstanceOf(RandomSourceInterface::class, $source);
        self::assertSame(7, $source->nextInt(7, 7));
    }
}
