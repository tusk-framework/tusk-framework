<?php

declare(strict_types=1);

namespace Tusk\Runtime\Observability;

use Throwable;

interface LifecycleObserverInterface
{
    public function applicationStarted(): void;

    public function workerStarted(): void;

    public function requestStarted(mixed $request = null): void;

    public function requestFinished(mixed $response = null, ?Throwable $exception = null): void;

    public function workerStopped(): void;

    public function applicationStopped(): void;
}
