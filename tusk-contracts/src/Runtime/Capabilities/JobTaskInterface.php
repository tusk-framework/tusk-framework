<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface JobTaskInterface
{
    public function message(): QueueMessageInterface;

    public function attempt(): int;

    public function acknowledge(): void;

    public function retry(?int $delaySeconds = null): void;

    public function fail(string $reason): void;
}
