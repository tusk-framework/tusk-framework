<?php

namespace Tusk\Contracts\Runtime\Jobs;

interface JobHandlerInterface
{
    public function handle(JobContext $job): void;
}
