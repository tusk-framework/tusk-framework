<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Random;

use InvalidArgumentException;
use Tusk\Contracts\Cloud\Resilience\RandomSourceInterface;

final readonly class SecureRandomSource implements RandomSourceInterface
{
    public function nextInt(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random range minimum exceeds maximum.');
        }

        return random_int($minimum, $maximum);
    }
}
