<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface JobTaskInterface
{
    public function acknowledge(): void;

    public function retry(?int $delaySeconds = null): void;
}
