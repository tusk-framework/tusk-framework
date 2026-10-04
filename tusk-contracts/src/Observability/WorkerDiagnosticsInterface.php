<?php

declare(strict_types=1);

namespace Tusk\Contracts\Observability;

interface WorkerDiagnosticsInterface
{
    public function snapshot(): WorkerDiagnosticsSnapshot;
}
