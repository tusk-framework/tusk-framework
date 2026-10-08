<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

interface RandomSourceInterface
{
    /**
     * Return an integer within the inclusive [$minimum, $maximum] range.
     * Equal bounds are valid and must return that value. Inverted bounds
     * ($minimum > $maximum) must throw InvalidArgumentException.
     */
    public function nextInt(int $minimum, int $maximum): int;
}
