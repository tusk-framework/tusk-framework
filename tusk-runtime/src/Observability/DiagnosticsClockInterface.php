<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use DateTimeImmutable;

interface DiagnosticsClockInterface
{
    public function now(): DateTimeImmutable;

    public function monotonicSeconds(): float;
}
