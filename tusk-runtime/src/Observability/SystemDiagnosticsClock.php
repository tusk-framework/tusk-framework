<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use DateTimeImmutable;

final class SystemDiagnosticsClock implements DiagnosticsClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }

    public function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
