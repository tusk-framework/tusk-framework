<?php

namespace Tusk\Contracts\Runtime;

use Throwable;
use Tusk\Contracts\Runtime\Jobs\JobContext;

interface LifecycleManagerInterface
{
    public function applicationStart(): void;

    public function workerStart(): void;

    public function requestStart(): void;

    public function requestEnd(): void;

    public function jobStart(JobContext $job): void;

    public function jobEnd(?Throwable $exception = null): void;

    public function workerStop(): void;

    public function applicationStop(): void;

    public function wrap(callable $handler): callable;
}
