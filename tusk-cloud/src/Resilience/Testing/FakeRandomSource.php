<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Testing;

use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\RandomSourceInterface;
use UnderflowException;

final class FakeRandomSource implements RandomSourceInterface
{
    /** @var list<array{int, int}> */
    private array $requestedRanges = [];

    /** @param list<int> $values */
    public function __construct(private array $values = []) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random range minimum exceeds maximum.');
        }

        $this->requestedRanges[] = [$minimum, $maximum];
        if ($this->values === []) {
            throw new UnderflowException('No fake random values remain.');
        }

        $value = array_shift($this->values);
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Fake random value is outside the requested range.');
        }

        return $value;
    }

    /** @return list<array{int, int}> */
    public function requestedRanges(): array
    {
        return $this->requestedRanges;
    }
}
