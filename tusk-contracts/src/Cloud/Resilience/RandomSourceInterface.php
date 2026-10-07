<?php

declare(strict_types=1);

namespace Tusk\Contracts\Cloud\Resilience;

interface RandomSourceInterface
{
    public function nextInt(int $minimum, int $maximum): int;
}
