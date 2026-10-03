<?php

namespace Tusk\Contracts\Runtime;

interface LifecycleManagerInterface
{
    public function applicationStart(): void;

    public function workerStart(): void;

    public function requestStart(): void;

    public function requestEnd(): void;

    public function workerStop(): void;

    public function applicationStop(): void;

    public function wrap(callable $handler): callable;
}
